<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingRoomAssignmentSource;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTeamUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;

final class CleaningBookingTeamService
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

    public function normalizeAssignmentMode(?string $assignmentMode, ?int $preferredWorkerId, ?int $numberOfWorkers = null): string
    {
        $normalized = $assignmentMode !== null ? mb_strtolower(mb_trim($assignmentMode)) : null;

        if ($normalized === CleaningAssignmentMode::PreferredWorker->value) {
            return CleaningAssignmentMode::PreferredWorker->value;
        }

        if ($normalized === CleaningAssignmentMode::OpenCount->value) {
            return CleaningAssignmentMode::OpenCount->value;
        }

        if ($preferredWorkerId !== null && ($numberOfWorkers === null || $numberOfWorkers <= 1)) {
            return CleaningAssignmentMode::PreferredWorker->value;
        }

        return CleaningAssignmentMode::OpenCount->value;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $workerRoomAssignments
     */
    public function syncRooms(CleaningBooking $booking, ?array $workerRoomAssignments = null): void
    {
        DB::transaction(function () use ($booking, $workerRoomAssignments): void {
            $booking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            CleaningBookingRoom::query()
                ->where('cleaning_booking_id', $booking->id)
                ->delete();

            $plan = WorkerRoomAssignmentPlanner::plan(
                is_array($booking->property_details) ? $booking->property_details : [],
                $workerRoomAssignments,
                $this->resolveAssignmentMode($booking),
                max(1, (int) ($booking->number_of_workers ?? 1)),
                $booking->preferred_worker_id !== null ? (int) $booking->preferred_worker_id : null,
            );
            $rooms = $plan['derivedRooms'];
            $roomPlans = $plan['roomPlans'];

            foreach ($rooms as $room) {
                $plannedAssignment = $roomPlans[$room['room_key']] ?? null;

                CleaningBookingRoom::query()->create([
                    'cleaning_booking_id' => $booking->id,
                    'room_key' => $room['room_key'],
                    'room_type' => $room['room_type'],
                    'room_size' => $room['room_size'],
                    'display_label' => $room['display_label'],
                    'weight' => $room['weight'],
                    'planned_worker_slot' => $plannedAssignment['workerSlot'] ?? null,
                    'planned_preferred_worker_id' => $plannedAssignment['preferredWorkerId'] ?? null,
                    'assigned_worker_id' => null,
                    'assignment_source' => null,
                ]);
            }
        });

        $this->broadcastTeamUpdated($booking->fresh(['rooms', 'workerAssignments.worker.user']));
    }

    public function acceptWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): CleaningBooking
    {
        return DB::transaction(function () use ($booking, $worker, $roomIds): CleaningBooking {
            $booking = $this->lockBooking($booking->id);

            $this->ensureAcceptableBooking($booking, $worker);

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                $assignment = CleaningBookingWorkerAssignment::query()->create([
                    'cleaning_booking_id' => $booking->id,
                    'worker_id' => $worker->id,
                    'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
                    'accepted_at' => now(),
                    'room_count' => 0,
                    'rooms_weight' => 0,
                    'service_share_amount' => 0,
                    'travel_fee' => 0,
                    'admin_margin_amount' => 0,
                    'worker_amount' => 0,
                    'currency' => (string) config('app.currency', 'SYP'),
                ]);
            } else {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::Accepted,
                    'accepted_at' => $assignment->accepted_at ?? now(),
                ])->save();
            }

            if ($roomIds !== null) {
                $this->claimRoomsForWorker($booking, $worker->id, $roomIds, CleaningBookingRoomAssignmentSource::Worker);
            }

            $booking = $this->recalculateBookingTeam($booking, finalizeBooking: $this->isTeamFulfilled($booking));

            return $booking->fresh([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'services',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ]);
        });
    }

    public function claimRooms(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): CleaningBooking
    {
        return DB::transaction(function () use ($booking, $worker, $roomIds): CleaningBooking {
            $booking = $this->lockBooking($booking->id);

            $assignment = $this->acceptedAssignmentForWorker($booking->id, $worker->id);

            if ($assignment === null) {
                throw new InvalidArgumentException('Worker must accept the booking before claiming rooms.');
            }

            if ($booking->status !== CleaningBookingStatus::Pending) {
                throw new InvalidArgumentException('Rooms can only be claimed while the booking is still searching.');
            }

            $this->claimRoomsForWorker(
                $booking,
                $worker->id,
                $roomIds,
                CleaningBookingRoomAssignmentSource::Worker,
            );

            $booking = $this->recalculateBookingTeam($booking, finalizeBooking: $this->isTeamFulfilled($booking));

            return $booking->fresh([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'services',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ]);
        });
    }

    public function rejectWorker(CleaningBooking $booking, Worker $worker, ?string $reason = null): CleaningBooking
    {
        return DB::transaction(function () use ($booking, $worker, $reason): CleaningBooking {
            $booking = $this->lockBooking($booking->id);

            if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true)) {
                throw new InvalidArgumentException('Booking cannot be rejected in current status.');
            }

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if ($assignment !== null) {
                $assignment->forceFill([
                    'status' => filled($reason)
                        ? CleaningBookingWorkerAssignmentStatus::Rejected
                        : CleaningBookingWorkerAssignmentStatus::Withdrawn,
                    'room_count' => 0,
                    'rooms_weight' => 0,
                    'service_share_amount' => 0,
                    'travel_fee' => 0,
                    'admin_margin_amount' => 0,
                    'worker_amount' => 0,
                ])->save();
            }

            $booking->rejections()->updateOrCreate(
                ['worker_id' => $worker->id],
                [
                    'reason' => $reason,
                    'rejected_at' => now(),
                ]
            );

            CleaningBookingRoom::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('assigned_worker_id', $worker->id)
                ->update([
                    'assigned_worker_id' => null,
                    'assignment_source' => null,
                ]);

            $booking = $this->recalculateBookingTeam($booking, finalizeBooking: false);

            return $booking->fresh([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'services',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ]);
        });
    }

    /**
     * @param  array<int, array{roomId:int, workerId:?int}>  $assignments
     */
    public function assignRoomsFromCustomer(CleaningBooking $booking, array $assignments): CleaningBooking
    {
        return DB::transaction(function () use ($booking, $assignments): CleaningBooking {
            $booking = $this->lockBooking($booking->id);

            if (in_array($booking->status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true)) {
                throw new InvalidArgumentException('Rooms cannot be reassigned in the current status.');
            }

            $acceptedWorkerIds = $this->acceptedAssignmentWorkerIds($booking->id);
            $roomsById = CleaningBookingRoom::query()
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($assignments as $assignment) {
                $roomId = (int) $assignment['roomId'];
                $workerId = isset($assignment['workerId']) && $assignment['workerId'] !== null ? (int) $assignment['workerId'] : null;

                $room = $roomsById->get($roomId);
                if (! $room instanceof CleaningBookingRoom) {
                    throw new InvalidArgumentException('One or more room assignments are invalid.');
                }

                if ($workerId !== null && ! in_array($workerId, $acceptedWorkerIds, true)) {
                    throw new InvalidArgumentException('Rooms can only be assigned to accepted workers.');
                }

                $room->forceFill([
                    'assigned_worker_id' => $workerId,
                    'assignment_source' => $workerId !== null
                        ? CleaningBookingRoomAssignmentSource::Customer
                        : null,
                ])->save();
            }

            $shouldFinalize = $this->isTeamFulfilled($booking) || $booking->status === CleaningBookingStatus::WorkerAssigned;

            $booking = $this->recalculateBookingTeam($booking, finalizeBooking: $shouldFinalize);

            return $booking->fresh([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'services',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ]);
        });
    }

    public function recalculateBookingTeam(CleaningBooking $booking, bool $finalizeBooking = false): CleaningBooking
    {
        $booking = $this->lockBooking($booking->id);

        $roomQuery = CleaningBookingRoom::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate();

        $assignmentQuery = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted)
            ->lockForUpdate();

        $rooms = $roomQuery->get()->values();
        $acceptedAssignments = $assignmentQuery->get()->values();
        $totalRoomWeight = round((float) $rooms->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);

        if ($acceptedAssignments->isNotEmpty()) {
            $this->applyPlannedAssignmentsToAcceptedWorkers($booking, $rooms, $acceptedAssignments);
            $rooms = CleaningBookingRoom::query()
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->get()
                ->values();
            $totalRoomWeight = round((float) $rooms->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);
        }

        if ($finalizeBooking && $acceptedAssignments->isNotEmpty()) {
            $this->autoBalanceUnassignedRooms($rooms, $acceptedAssignments);
            $rooms = CleaningBookingRoom::query()
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->get()
                ->values();
            $totalRoomWeight = round((float) $rooms->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);
        }

        $subtotal = round(((float) ($booking->base_price ?? 0)) + ((float) ($booking->addons_total ?? 0)), 2);
        $totalTravelFee = 0.0;
        $totalAdminMargin = 0.0;
        $primaryWorkerId = null;
        $primaryAcceptedAt = null;

        foreach ($acceptedAssignments as $assignment) {
            $workerRooms = $rooms->filter(
                fn (CleaningBookingRoom $room): bool => (int) ($room->assigned_worker_id ?? 0) === (int) $assignment->worker_id
            );

            $roomCount = $workerRooms->count();
            $roomsWeight = round((float) $workerRooms->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);
            $serviceShare = $totalRoomWeight > 0
                ? round($subtotal * ($roomsWeight / $totalRoomWeight), 2)
                : 0.0;

            $worker = $assignment->relationLoaded('worker') ? $assignment->worker : Worker::query()->find($assignment->worker_id);

            $travelFee = 0.0;
            $adminMargin = 0.0;
            $workerAmount = round($serviceShare, 2);

            if ($worker instanceof Worker && $booking->address_latitude !== null && $booking->address_longitude !== null) {
                $pricing = $this->pricingCalculator->finalizedForWorker(
                    $serviceShare,
                    0.0,
                    (float) $booking->address_latitude,
                    (float) $booking->address_longitude,
                    $worker,
                );

                $travelFee = (float) $pricing['travelFee'];
                $adminMargin = (float) $pricing['adminMargin'];
                $workerAmount = round($serviceShare + $travelFee, 2);
            }

            $assignment->forceFill([
                'room_count' => $roomCount,
                'rooms_weight' => $roomsWeight,
                'service_share_amount' => $serviceShare,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                'worker_amount' => $workerAmount,
                'currency' => (string) config('app.currency', 'SYP'),
            ])->save();

            $totalTravelFee += $travelFee;
            $totalAdminMargin += $adminMargin;

            if (
                $primaryAcceptedAt === null
                || $assignment->accepted_at === null
                || $assignment->accepted_at->lt($primaryAcceptedAt)
            ) {
                $primaryAcceptedAt = $assignment->accepted_at;
                $primaryWorkerId = (int) $assignment->worker_id;
            }
        }

        if ($finalizeBooking && $acceptedAssignments->isNotEmpty()) {
            $booking->forceFill([
                'status' => CleaningBookingStatus::WorkerAssigned,
                'worker_id' => $primaryWorkerId,
                'travel_fee' => round($totalTravelFee, 2),
                'admin_margin_amount' => round($totalAdminMargin, 2),
                'total_price' => round($acceptedAssignments->sum(fn (CleaningBookingWorkerAssignment $assignment): float => (float) $assignment->service_share_amount + (float) $assignment->travel_fee + (float) $assignment->admin_margin_amount), 2),
                'travel_distance_km' => null,
                'is_pricing_final' => true,
            ])->save();
        } else {
            $booking->forceFill([
                'status' => CleaningBookingStatus::Pending,
                'worker_id' => null,
                'travel_fee' => 0,
                'admin_margin_amount' => 0,
                'total_price' => $subtotal,
                'travel_distance_km' => null,
                'is_pricing_final' => false,
            ])->save();
        }

        $updated = $booking->fresh([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'services',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);

        $this->broadcastTeamUpdated($updated);

        return $updated;
    }

    public function teamSummary(CleaningBooking $booking): array
    {
        $booking = $booking->relationLoaded('workerAssignments') && $booking->relationLoaded('rooms')
            ? $booking
            : $booking->fresh(['rooms', 'workerAssignments']);

        $required = max(1, (int) ($booking->number_of_workers ?? 1));
        $accepted = $this->acceptedAssignmentsCollection($booking->id, $booking)->count();

        return [
            'cleaningBookingId' => $booking->id,
            'assignmentMode' => $this->resolveAssignmentMode($booking),
            'requiredWorkers' => $required,
            'acceptedWorkers' => $accepted,
            'remainingWorkers' => max(0, $required - $accepted),
            'isFulfilled' => $accepted >= $required,
            'status' => $booking->status?->value ?? $booking->status,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{workerSlot:int, preferredWorkerId:?int, rooms:array<int, array{roomKey:string, roomType:string, roomSize:string}>, roomsWeight:float}>
     */
    public function exportPlannedWorkerRoomAssignments(CleaningBooking $booking): array
    {
        $rooms = $booking->relationLoaded('rooms')
            ? $booking->rooms
            : $booking->rooms()->orderBy('planned_worker_slot')->orderBy('room_key')->get();

        $grouped = [];

        foreach ($rooms as $room) {
            if ($room->planned_worker_slot === null) {
                continue;
            }

            $slot = (int) $room->planned_worker_slot;

            $grouped[$slot] ??= [
                'workerSlot' => $slot,
                'preferredWorkerId' => $room->planned_preferred_worker_id !== null ? (int) $room->planned_preferred_worker_id : null,
                'rooms' => [],
                'roomsWeight' => 0.0,
            ];

            $grouped[$slot]['rooms'][] = [
                'roomKey' => $room->room_key,
                'roomType' => $room->room_type,
                'roomSize' => (string) $room->room_size,
            ];
            $grouped[$slot]['roomsWeight'] = round($grouped[$slot]['roomsWeight'] + (float) $room->weight, 2);
        }

        ksort($grouped);

        return array_values($grouped);
    }

    private function resolveAssignmentMode(CleaningBooking $booking): string
    {
        return $this->normalizeAssignmentMode(
            $booking->assignment_mode?->value ?? $booking->assignment_mode,
            $booking->preferred_worker_id,
            (int) ($booking->number_of_workers ?? 1),
        );
    }

    private function lockBooking(int $bookingId): CleaningBooking
    {
        return CleaningBooking::query()
            ->whereKey($bookingId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureAcceptableBooking(CleaningBooking $booking, Worker $worker): void
    {
        if ($booking->status === CleaningBookingStatus::Cancelled || $booking->status === CleaningBookingStatus::Completed) {
            throw new InvalidArgumentException('Booking cannot be accepted in current status.');
        }

        if ($booking->assignment_mode instanceof CleaningAssignmentMode && $booking->assignment_mode === CleaningAssignmentMode::PreferredWorker && (int) $booking->preferred_worker_id !== $worker->id) {
            throw new InvalidArgumentException('Booking is reserved for a different preferred worker.');
        }

        if ($booking->preferred_worker_id !== null && $booking->assignment_mode === null && (int) $booking->preferred_worker_id !== $worker->id && (int) ($booking->number_of_workers ?? 1) === 1) {
            throw new InvalidArgumentException('Booking is reserved for a different preferred worker.');
        }

        if (
            $booking->gender_preference !== null
            && (string) $booking->gender_preference->value !== 'any'
            && $worker->gender !== $booking->gender_preference->value
        ) {
            throw new InvalidArgumentException('Booking gender preference does not match worker profile.');
        }

        if ($worker->home_address === null || mb_trim($worker->home_address) === '') {
            throw new InvalidArgumentException('Worker home location is required before accepting bookings.');
        }

        if ($worker->home_latitude === null || $worker->home_longitude === null) {
            throw new InvalidArgumentException('Worker home location is required before accepting bookings.');
        }

        if ($booking->address_latitude === null || $booking->address_longitude === null) {
            throw new InvalidArgumentException('Customer location coordinates are required before accepting bookings.');
        }

        if ($this->isTeamFulfilled($booking) && $this->acceptedAssignmentForWorker($booking->id, $worker->id) === null) {
            throw new InvalidArgumentException('Booking already has the required number of workers.');
        }
    }

    private function isTeamFulfilled(CleaningBooking $booking): bool
    {
        $required = max(1, (int) ($booking->number_of_workers ?? 1));
        $accepted = $this->acceptedAssignmentsCollection($booking->id, $booking)->count();

        return $accepted >= $required;
    }

    private function acceptedAssignmentForWorker(int $bookingId, int $workerId): ?CleaningBookingWorkerAssignment
    {
        return CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $bookingId)
            ->where('worker_id', $workerId)
            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted)
            ->first();
    }

    /**
     * @return EloquentCollection<int, CleaningBookingWorkerAssignment>
     */
    private function acceptedAssignmentsCollection(int $bookingId, ?CleaningBooking $booking = null): EloquentCollection
    {
        if ($booking !== null && $booking->relationLoaded('workerAssignments')) {
            return $booking->workerAssignments->filter(
                static fn (CleaningBookingWorkerAssignment $assignment): bool => $assignment->status === CleaningBookingWorkerAssignmentStatus::Accepted
            )->values();
        }

        return CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $bookingId)
            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted)
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function acceptedAssignmentWorkerIds(int $bookingId): array
    {
        return $this->acceptedAssignmentsCollection($bookingId)
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->all();
    }

    /**
     * @param  array<int, mixed>|null  $roomIds
     */
    private function claimRoomsForWorker(CleaningBooking $booking, int $workerId, ?array $roomIds, CleaningBookingRoomAssignmentSource $source): void
    {
        $query = CleaningBookingRoom::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate();

        if ($roomIds !== null) {
            $roomIds = array_values(array_unique(array_map('intval', $roomIds)));
            $query->whereIn('id', $roomIds);
        } else {
            $query->whereNull('assigned_worker_id');
        }

        $rooms = $query->get();

        if ($roomIds !== null && $rooms->count() !== count($roomIds)) {
            throw new InvalidArgumentException('One or more rooms do not belong to this booking.');
        }

        foreach ($rooms as $room) {
            if ($room->assigned_worker_id !== null && (int) $room->assigned_worker_id !== $workerId) {
                throw new InvalidArgumentException('One or more rooms are already assigned to another worker.');
            }

            $room->forceFill([
                'assigned_worker_id' => $workerId,
                'assignment_source' => $source,
            ])->save();
        }
    }

    /**
     * @param  EloquentCollection<int, CleaningBookingRoom>  $rooms
     * @param  EloquentCollection<int, CleaningBookingWorkerAssignment>  $acceptedAssignments
     */
    private function autoBalanceUnassignedRooms(EloquentCollection $rooms, EloquentCollection $acceptedAssignments): void
    {
        $unassignedRooms = $rooms
            ->whereNull('assigned_worker_id')
            ->sortByDesc(fn (CleaningBookingRoom $room): float => (float) $room->weight)
            ->values();

        if ($unassignedRooms->isEmpty() || $acceptedAssignments->isEmpty()) {
            return;
        }

        $assignedWeights = $acceptedAssignments
            ->mapWithKeys(fn (CleaningBookingWorkerAssignment $assignment): array => [
                (int) $assignment->worker_id => round((float) $rooms
                    ->where('assigned_worker_id', (int) $assignment->worker_id)
                    ->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2),
            ])
            ->all();

        foreach ($unassignedRooms as $room) {
            asort($assignedWeights, SORT_NUMERIC);
            $targetWorkerId = (int) array_key_first($assignedWeights);

            CleaningBookingRoom::query()
                ->whereKey($room->id)
                ->update([
                    'assigned_worker_id' => $targetWorkerId,
                    'assignment_source' => CleaningBookingRoomAssignmentSource::Auto->value,
                ]);

            $assignedWeights[$targetWorkerId] = round($assignedWeights[$targetWorkerId] + (float) $room->weight, 2);
        }
    }

    private function broadcastTeamUpdated(CleaningBooking $booking): void
    {
        BroadcastAfterResponse::send(new CleaningBookingTeamUpdated(
            $booking->id,
            $this->teamSummary($booking),
        ));
    }

    /**
     * @param  EloquentCollection<int, CleaningBookingRoom>  $rooms
     * @param  EloquentCollection<int, CleaningBookingWorkerAssignment>  $acceptedAssignments
     */
    private function applyPlannedAssignmentsToAcceptedWorkers(
        CleaningBooking $booking,
        EloquentCollection $rooms,
        EloquentCollection $acceptedAssignments,
    ): void {
        $slotWorkerMap = $this->slotWorkerMap($booking, $acceptedAssignments);

        if ($slotWorkerMap === []) {
            return;
        }

        foreach ($rooms as $room) {
            if ($room->assigned_worker_id !== null || $room->planned_worker_slot === null) {
                continue;
            }

            $workerId = $slotWorkerMap[(int) $room->planned_worker_slot] ?? null;
            if ($workerId === null) {
                continue;
            }

            $room->forceFill([
                'assigned_worker_id' => $workerId,
                'assignment_source' => CleaningBookingRoomAssignmentSource::Customer,
            ])->save();
        }
    }

    /**
     * @param  EloquentCollection<int, CleaningBookingWorkerAssignment>  $acceptedAssignments
     * @return array<int, int>
     */
    private function slotWorkerMap(CleaningBooking $booking, EloquentCollection $acceptedAssignments): array
    {
        $orderedAssignments = $acceptedAssignments
            ->sort(static function (CleaningBookingWorkerAssignment $left, CleaningBookingWorkerAssignment $right): int {
                $leftAcceptedAt = $left->accepted_at?->getTimestamp() ?? 0;
                $rightAcceptedAt = $right->accepted_at?->getTimestamp() ?? 0;

                if ($leftAcceptedAt !== $rightAcceptedAt) {
                    return $leftAcceptedAt <=> $rightAcceptedAt;
                }

                return $left->id <=> $right->id;
            })
            ->values();

        $mapping = [];

        if ($this->resolveAssignmentMode($booking) === CleaningAssignmentMode::PreferredWorker->value) {
            $preferredWorkerId = $booking->preferred_worker_id !== null ? (int) $booking->preferred_worker_id : null;

            foreach ($orderedAssignments as $assignment) {
                if ($preferredWorkerId !== null && (int) $assignment->worker_id === $preferredWorkerId) {
                    $mapping[1] = (int) $assignment->worker_id;

                    return $mapping;
                }
            }
        }

        foreach ($orderedAssignments as $index => $assignment) {
            $mapping[$index + 1] = (int) $assignment->worker_id;
        }

        return $mapping;
    }
}

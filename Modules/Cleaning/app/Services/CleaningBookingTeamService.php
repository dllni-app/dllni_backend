<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        private readonly CleaningCouponPricingService $couponPricingService,
        private readonly DepositService $depositService,
        private readonly WorkerTrustService $workerTrustService,
        private readonly WorkerOrderSolvencyService $solvencyService,
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

    public function syncRooms(CleaningBooking $booking, ?array $workerRoomAssignments = null): void
    {
        if ($workerRoomAssignments !== null && $workerRoomAssignments !== []) {
            throw ValidationException::withMessages(['workerRoomAssignments' => ['Worker-to-room allocation is no longer supported.']]);
        }

        DB::transaction(function () use ($booking): void {
            $booking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLegacyTeamMutationAllowed($booking);

            $hasAcceptedAssignments = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->exists();

            if ($hasAcceptedAssignments) {
                throw ValidationException::withMessages([
                    'order' => ['Room or pricing fields cannot be rebuilt after a worker has accepted the booking.'],
                ]);
            }

            CleaningBookingRoom::query()
                ->where('cleaning_booking_id', $booking->id)
                ->delete();

            $plan = WorkerRoomAssignmentPlanner::plan(
                is_array($booking->property_details) ? $booking->property_details : [],
                null,
                $this->resolveAssignmentMode($booking),
                max(1, (int) ($booking->number_of_workers ?? 1)),
                $booking->preferred_worker_id !== null ? (int) $booking->preferred_worker_id : null,
            );
            $rooms = $plan['derivedRooms'];

            foreach ($rooms as $room) {
                CleaningBookingRoom::query()->create([
                    'cleaning_booking_id' => $booking->id,
                    'room_key' => $room['room_key'],
                    'room_type' => $room['room_type'],
                    'room_size' => $room['room_size'],
                    'display_label' => $room['display_label'],
                    'weight' => $room['weight'],
                    'planned_worker_slot' => null,
                    'planned_preferred_worker_id' => null,
                    'assigned_worker_id' => null,
                    'assignment_source' => null,
                ]);
            }
        });

        $this->broadcastTeamUpdated($booking->fresh(['rooms', 'workerAssignments.worker.user']));
    }

    public function acceptWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): CleaningBooking
    {
        if ($roomIds !== null && $roomIds !== []) {
            throw ValidationException::withMessages(['roomIds' => ['Worker-to-room allocation is no longer supported.']]);
        }

        return DB::transaction(function () use ($booking, $worker): CleaningBooking {
            $booking = $this->lockBooking($booking->id);
            $this->assertLegacyTeamMutationAllowed($booking);
            $worker = Worker::query()
                ->whereKey($worker->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureAcceptableBooking($booking, $worker);
            $this->solvencyService->assertWorkerCanAcceptBooking($worker, $booking);

            if (! $this->depositService->isWorkerEligibleForDispatch($worker)) {
                throw new InvalidArgumentException('Worker is not eligible to accept new requests.');
            }

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                $assignment = CleaningBookingWorkerAssignment::query()->create([
                    'cleaning_booking_id' => $booking->id,
                    'worker_id' => $worker->id,
                    'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
                    'accepted_at' => now(),
                    'start_approved_at' => null,
                    'room_count' => 0,
                    'rooms_weight' => 0,
                    'service_share_amount' => 0,
                    'travel_fee' => 0,
                    'admin_margin_amount' => 0,
                    'worker_amount' => 0,
                    'currency' => (string) config('app.currency', 'SYP'),
                ]);
            } else {
                if (in_array($assignment->status, CleaningBookingWorkerAssignmentStatus::acceptedStatuses(), true)) {
                    throw new InvalidArgumentException('Worker has already accepted this booking.');
                }

                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
                    'accepted_at' => $assignment->accepted_at ?? now(),
                    'start_approved_at' => null,
                ])->save();
            }

            app(CleaningSpecialistEquipmentReservationService::class)->confirmForBooking($booking, $worker);

            $booking = $this->recalculateBookingTeam(
                $booking,
                finalizeBooking: $this->isTeamFulfilled($booking),
                applyPlannedAssignments: false,
            );

            return $booking->fresh([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ]);
        });
    }


    public function rejectWorker(CleaningBooking $booking, Worker $worker, ?string $reason = null, bool $applyWorkerPenalty = true): CleaningBooking
    {
        return DB::transaction(function () use ($booking, $worker, $reason, $applyWorkerPenalty): CleaningBooking {
            $booking = $this->lockBooking($booking->id);

            if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true)) {
                throw new InvalidArgumentException('Booking cannot be rejected in current status.');
            }

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            $hadAccepted = $assignment !== null
                && in_array($assignment->status, CleaningBookingWorkerAssignmentStatus::acceptedStatuses(), true);

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
                    'start_approved_at' => null,
                ])->save();
            }

            $booking->rejections()->updateOrCreate(
                ['worker_id' => $worker->id],
                [
                    'reason' => $reason,
                    'rejected_at' => now(),
                ]
            );

            if ($hadAccepted) {
                app(CleaningSpecialistEquipmentReservationService::class)->releaseUnstartedForWorker($booking, $worker);
            }

            $booking = $this->recalculateBookingTeam($booking, finalizeBooking: false);

            if ($hadAccepted && $applyWorkerPenalty) {
                $this->workerTrustService->applyRejectAfterAcceptPenalty($worker, $booking);
            }

            return $booking->fresh([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
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
    /**
     * Replace a team member atomically without a voluntary cancellation penalty.
     */
    public function replaceWorkerByAdmin(
        CleaningBooking $booking,
        Worker $outgoing,
        Worker $incoming,
        ?string $reason = null,
    ): CleaningBooking {
        return DB::transaction(function () use ($booking, $outgoing, $incoming, $reason): CleaningBooking {
            $booking = $this->lockBooking($booking->id);
            if (! in_array($booking->status, [
                CleaningBookingStatus::Pending,
                CleaningBookingStatus::WorkerAssigned,
            ], true) || $booking->started_travel_at !== null || $booking->work_started_at !== null) {
                throw new InvalidArgumentException('لا يمكن استبدال عامل بعد بدء رحلة الفريق أو تنفيذ الطلب.');
            }
            if ((int) $outgoing->id === (int) $incoming->id) {
                throw new InvalidArgumentException('يجب اختيار عامل بديل مختلف عن العامل الحالي.');
            }
            $outgoingAssignment = $this->acceptedAssignmentForWorker($booking->id, $outgoing->id);
            if ($outgoingAssignment === null) {
                throw new InvalidArgumentException('العامل المطلوب استبداله لم يعد ضمن الفريق.');
            }
            if ($outgoingAssignment->started_travel_at !== null || $outgoingAssignment->work_started_at !== null) {
                throw new InvalidArgumentException('لا يمكن استبدال عامل بعد بدء رحلته أو عمله.');
            }

            $booking = $this->rejectWorker($booking, $outgoing, $reason, false);

            // Keep customer-selected worker scope consistent with the replacement.
            $newWorkerIds = array_values(array_unique(array_map(
                static fn (int $workerId): int => $workerId === (int) $outgoing->id
                    ? (int) $incoming->id : $workerId,
                $booking->specificWorkerIds(),
            )));
            $booking->forceFill([
                'specific_worker_ids' => $newWorkerIds,
                'preferred_worker_id' => (int) ($booking->preferred_worker_id ?? 0) === (int) $outgoing->id
                    ? (int) $incoming->id : $booking->preferred_worker_id,
            ])->save();

            return $this->acceptWorker($booking->fresh(), $incoming);
        });
    }


    public function recalculateBookingTeam(
        CleaningBooking $booking,
        bool $finalizeBooking = false,
        bool $applyPlannedAssignments = true,
    ): CleaningBooking {
        $booking = $this->lockBooking($booking->id);
        $this->assertLegacyTeamMutationAllowed($booking);

        $roomQuery = CleaningBookingRoom::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate();

        $assignmentQuery = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->lockForUpdate();

        $rooms = $roomQuery->get()->values();
        $acceptedAssignments = $assignmentQuery->get()->values();
        $totalRoomWeight = round((float) $rooms->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);

        // Rooms are booking tasks shared by all accepted team workers.

        // Supervisor charges are collected for the supervisor's group hours,
        // not part of the workers' distributable cleaning wage.
        $supervisorFee = max(0.0, (float) data_get($booking->property_details, '_supervisor_pricing.fee', 0));
        $subtotal = round(max(0.0,
            ((float) ($booking->base_price ?? 0)) + ((float) ($booking->addons_total ?? 0)) - $supervisorFee
        ), 2);
        $includedAdminMarginBase = $this->pricingCalculator->minimumOrderAdminMarginBase(
            (float) ($booking->base_price ?? 0),
            (string) $booking->property_type,
            (float) ($booking->addons_total ?? 0),
            (float) ($booking->travel_fee ?? 0),
            (float) ($booking->total_price ?? 0),
        );
        $requiredWorkers = max(1, (int) ($booking->number_of_workers ?? 1));
        $totalTravelFee = 0.0;
        $totalAdminMargin = 0.0;
        $primaryWorkerId = null;
        $primaryAcceptedAt = null;

        $remainingServiceCents = (int) round($subtotal * 100);
        foreach ($acceptedAssignments as $index => $assignment) {
            $roomCount = $rooms->count();
            $roomsWeight = $totalRoomWeight;
            $slotCents = intdiv($remainingServiceCents, max(1, $requiredWorkers - $index));
            $serviceShare = (float) ($slotCents / 100);
            $remainingServiceCents -= $slotCents;

            $workerIncludedAdminMarginBase = $includedAdminMarginBase > 0.0 && $subtotal > 0.0
                ? round($includedAdminMarginBase * ($serviceShare / $subtotal), 2)
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
                    $workerIncludedAdminMarginBase,
                );

                $travelFee = (float) $pricing['travelFee'];
                $adminMargin = (float) $pricing['adminMargin'];
                $workerAmount = max(
                    0.0,
                    round($serviceShare + $travelFee - (float) ($pricing['includedAdminMargin'] ?? 0.0), 2),
                );
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

        $periodApprovedTotal = (array) ($booking->property_details ?? []);
        $customerPriceLock = isset($periodApprovedTotal['_room_period_v2_approved_total'])
            ? round((float) $periodApprovedTotal['_room_period_v2_approved_total'], 2)
            : null;

        if ($finalizeBooking && $acceptedAssignments->isNotEmpty()) {
            $booking->forceFill([
                'status' => CleaningBookingStatus::WorkerAssigned,
                'worker_id' => $primaryWorkerId,
                'travel_fee' => round($totalTravelFee, 2),
                'admin_margin_amount' => round($totalAdminMargin, 2),
                'total_price' => $customerPriceLock ?? round($supervisorFee + $acceptedAssignments->sum(
                    fn (CleaningBookingWorkerAssignment $assignment): float => (float) $assignment->worker_amount + (float) $assignment->admin_margin_amount
                ), 2),
                'travel_distance_km' => null,
                'is_pricing_final' => true,
            ]);
            $this->couponPricingService->saveAfterTeamRecalculation($booking);
        } else {
            $provisionalPricing = $this->pricingCalculator->provisional(
                $subtotal,
                0.0,
                $includedAdminMarginBase,
                $requiredWorkers,
            );

            $booking->forceFill([
                'status' => CleaningBookingStatus::Pending,
                'worker_id' => null,
                'travel_fee' => round((float) $provisionalPricing['travelFee'], 2),
                'admin_margin_amount' => round((float) $provisionalPricing['adminMargin'], 2),
                'total_price' => $customerPriceLock ?? round((float) $provisionalPricing['totalPrice'] + $supervisorFee, 2),
                'travel_distance_km' => null,
                'is_pricing_final' => false,
            ]);
            $this->couponPricingService->saveAfterTeamRecalculation($booking);
        }

        $updated = $booking->fresh([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
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
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isFulfilled' => $accepted >= $required,
            'status' => $booking->status?->value ?? $booking->status,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{workerSlot:int, preferredWorkerId:?int, rooms:array<int, array{roomKey:string, roomType:string, roomSize:string}>, roomsWeight:float}>
     */


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

    private function assertLegacyTeamMutationAllowed(CleaningBooking $booking): void
    {
        if ($booking->status === CleaningBookingStatus::PartiallyCompleted) {
            throw ValidationException::withMessages([
                'status' => ['This partially completed booking cannot use legacy team or room mutations.'],
            ]);
        }
    }

    private function ensureAcceptableBooking(CleaningBooking $booking, Worker $worker): void
    {
        if ($booking->status === CleaningBookingStatus::Cancelled || $booking->status === CleaningBookingStatus::Completed) {
            throw new InvalidArgumentException('Booking cannot be accepted in current status.');
        }

        if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true)) {
            throw new InvalidArgumentException('Booking cannot be accepted in current status.');
        }

        if ($booking->assignment_mode instanceof CleaningAssignmentMode && $booking->assignment_mode === CleaningAssignmentMode::PreferredWorker && (int) $booking->preferred_worker_id !== $worker->id) {
            throw new InvalidArgumentException('Booking is reserved for a different preferred worker.');
        }

        if ($booking->preferred_worker_id !== null && $booking->assignment_mode === null && (int) $booking->preferred_worker_id !== $worker->id && (int) ($booking->number_of_workers ?? 1) === 1) {
            throw new InvalidArgumentException('Booking is reserved for a different preferred worker.');
        }

        // Radius-free urgent dispatch must never override the customer's explicit worker scope.
        if (
            $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC
            && ! in_array((int) $worker->id, $booking->specificWorkerIds(), true)
            && (int) $booking->preferred_worker_id !== (int) $worker->id
        ) {
            throw new InvalidArgumentException('Booking is reserved for customer-selected workers.');
        }

        $explicitlySelected = (int) $booking->preferred_worker_id === (int) $worker->id
            || ($booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC
                && in_array((int) $worker->id, $booking->specificWorkerIds(), true));

        if (! $explicitlySelected
            && ! app(CleaningGeographicDispatchService::class)->isWithinRadius($worker, $booking)) {
            throw ValidationException::withMessages([
                'worker' => ['الطلب خارج نطاق المسافة المتاح لك حالياً.'],
            ]);
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
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->first();
    }

    /**
     * @return EloquentCollection<int, CleaningBookingWorkerAssignment>
     */
    private function acceptedAssignmentsCollection(int $bookingId, ?CleaningBooking $booking = null): EloquentCollection
    {
        if ($booking !== null && $booking->relationLoaded('workerAssignments')) {
            return $booking->workerAssignments->filter(
                static fn (CleaningBookingWorkerAssignment $assignment): bool => in_array($assignment->status, CleaningBookingWorkerAssignmentStatus::acceptedStatuses(), true)
            )->values();
        }

        return CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $bookingId)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
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

    /**
     * @param  EloquentCollection<int, CleaningBookingRoom>  $rooms
     * @param  EloquentCollection<int, CleaningBookingWorkerAssignment>  $acceptedAssignments
     */

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

    /**
     * @param  EloquentCollection<int, CleaningBookingWorkerAssignment>  $acceptedAssignments
     * @return array<int, int>
     */
}

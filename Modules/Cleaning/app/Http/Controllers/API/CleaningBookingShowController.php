<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingOperationalResource;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingShowController
{
    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingOperationalResource|JsonResponse
    {
        // Supervisor-managed work has a separate redacted read endpoint.
        if ($cleaning_booking->isSupervisedTeam()) {
            abort(404);
        }
        $worker = Auth::user()?->worker;
        if ($worker instanceof Worker && $this->isWorkerDispatchCandidate($cleaning_booking)) {
            return response()->json(['data' => $this->workerDiscoveryPayload($cleaning_booking, $worker)]);
        }
        if ($worker instanceof Worker && $this->shouldScopeV2WorkerRooms($cleaning_booking)) {
            abort_unless($this->workerOwnsAcceptedVisit($cleaning_booking, $worker), 404);

            return response()->json(['data' => $this->workerDiscoveryPayload($cleaning_booking, $worker)]);
        }

        $this->ensureWorkerCanViewBooking($cleaning_booking);

        return CleaningBookingOperationalResource::make($cleaning_booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
            'materials.material',
            'materials.unit',
            'specialServices.specialService',
        ]));
    }

    private function ensureWorkerCanViewBooking(CleaningBooking $booking): void
    {
        $user = Auth::user();
        $worker = $user?->worker;

        if (! $worker instanceof Worker) {
            abort_unless($user instanceof User && $user->exists, 403);
            $actor = User::query()->whereKey($user->getKey())->where('is_active', true)->first();
            abort_unless($actor instanceof User
                && $actor->hasAnyRole(['admin', 'Super Admin', 'Cleaning Ops Manager']), 403);

            return;
        }

        $hasAcceptedAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        $hasAcceptedSessionAssignment = $booking->sessions()
            ->whereHas('workerAssignments', function ($query) use ($worker): void {
                $query
                    ->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->exists();

        if (
            (int) ($booking->worker_id ?? 0) === (int) $worker->id
            || $hasAcceptedAssignment
            || $hasAcceptedSessionAssignment
        ) {
            return;
        }

        abort(404, 'Booking not found.');
    }

    private function isWorkerDispatchCandidate(CleaningBooking $booking): bool
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);
        if ($status !== CleaningBookingStatus::Pending) {
            return false;
        }

        return CleaningBooking::getQuery()
            ->whereKey($booking->id)
            ->forCurrentWorker(true)
            ->exists();
    }

    private function workerDiscoveryPayload(CleaningBooking $booking, Worker $worker): array
    {
        $booking->loadMissing([
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
        ]);
        $payload = CleaningBookingResource::make($booking)->resolve(request());

        $safe = Arr::only($payload, [
            'id', 'bookingNumber', 'displayTitle', 'status', 'statusLabel', 'globalStatus',
            'worker_order_status', 'worker_order_status_label', 'type', 'bookingKind',
            'numberOfWorkers', 'required_workers_count', 'accepted_workers_count', 'pending_workers_count',
            'genderPreference', 'propertyType', 'propertyTypeLabel', 'numberOfRooms', 'numberOfKitchens',
            'numberOfBalconies', 'numberOfSheds', 'estimatedHours', 'scheduledDate', 'scheduledTime',
            'totalHours', 'workerTotalHours', 'serviceShareAmount', 'travelFee', 'workerAmount',
            'workerOffer', 'worker_offer', 'myAssignment', 'worker_assignment', 'workerFinancials', 'workerPresentationState', 'isHotOrder',
            'urgencyLabel', 'createdAt', 'updatedAt',
        ]);

        $safe['visits'] = $booking->sessions()
            ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->with(['workerAssignments' => function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            }])
            ->orderBy('sequence')
            ->get()
            ->map(static function ($session): array {
                $assignment = $session->workerAssignments->first();

                return [
                    'id' => (int) $session->id,
                    'sequence' => (int) $session->sequence,
                    'status' => $session->status?->value ?? (string) $session->status,
                    'scheduledDate' => $session->scheduled_date?->toDateString(),
                    'scheduledTime' => mb_substr((string) $session->scheduled_time, 0, 5),
                    'requiredWorkers' => $session->requiredWorkerCount(),
                    'attendance' => [
                        'status' => $assignment?->status?->value ?? $assignment?->status,
                        'acceptedAt' => $assignment?->accepted_at?->toIso8601String(),
                        'startedTravelAt' => $assignment?->started_travel_at?->toIso8601String(),
                        'arrivedAt' => $assignment?->arrived_at?->toIso8601String(),
                        'workStartedAt' => $assignment?->work_started_at?->toIso8601String(),
                        'workFinishedAt' => $assignment?->work_finished_at?->toIso8601String(),
                    ],
                ];
            })
            ->values()
            ->all();

        $ownedRooms = [];
        if ($safe['visits'] !== []) {
            $ownedRooms = $this->workerOwnedRoomProjection($booking, $worker);
            $safe['address'] = $payload['address'] ?? null;
            $safe['addressLatitude'] = $payload['addressLatitude'] ?? null;
            $safe['addressLongitude'] = $payload['addressLongitude'] ?? null;
            $safe['roomAssignments'] = $ownedRooms;
        }
        if ($this->shouldScopeV2WorkerRooms($booking)) {
            $safe = $this->scopeNestedWorkerRoomData($safe, $ownedRooms);
        }

        return $safe;
    }

    private function workerOwnsAcceptedVisit(CleaningBooking $booking, Worker $worker): bool
    {
        return $booking->sessions()
            ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->exists();
    }

    private function workerOwnedRoomProjection(CleaningBooking $booking, Worker $worker): array
    {
        $sessionIds = $booking->sessions()
            ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->pluck('id');
        if ($sessionIds->isEmpty()) {
            return [];
        }

        $scheduledRooms = $booking->roomSchedules()
            ->with('room')
            ->whereIn('cleaning_booking_session_id', $sessionIds)
            ->get()
            ->map(static fn ($schedule): array => [
                'id' => (int) $schedule->cleaning_booking_room_id,
                'roomKey' => $schedule->room?->room_key,
                'roomType' => $schedule->room?->room_type,
                'roomSize' => $schedule->room?->room_size,
                'displayLabel' => $schedule->room?->display_label,
                'assignedWorkerId' => $schedule->room?->assigned_worker_id,
            ]);
        $periodRooms = $booking->roomPeriods()
            ->with('room')
            ->whereIn('cleaning_booking_session_id', $sessionIds)
            ->get()
            ->map(static fn ($period): array => [
                'id' => (int) $period->cleaning_booking_room_id,
                'roomKey' => $period->room?->room_key,
                'roomType' => $period->room_type,
                'roomSize' => $period->room_size,
                'displayLabel' => $period->room?->display_label,
                'assignedWorkerId' => $period->room?->assigned_worker_id,
            ]);

        return $scheduledRooms->concat($periodRooms)
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id:int,roomKey:mixed,roomType:mixed,roomSize:mixed,displayLabel:mixed,assignedWorkerId:mixed}>  $ownedRooms
     */
    private function scopeNestedWorkerRoomData(array $safe, array $ownedRooms): array
    {
        $ownedIds = array_fill_keys(array_map(static fn (array $room): int => (int) $room['id'], $ownedRooms), true);
        $ownedKeys = array_fill_keys(array_values(array_filter(array_map(
            static fn (array $room): ?string => is_string($room['roomKey'] ?? null) ? mb_trim($room['roomKey']) : null,
            $ownedRooms,
        ))), true);
        $ownedLabels = array_fill_keys(array_values(array_filter(array_map(
            static fn (array $room): ?string => is_string($room['displayLabel'] ?? null) ? mb_trim($room['displayLabel']) : null,
            $ownedRooms,
        ))), true);

        foreach (['workerOffer', 'worker_offer', 'myAssignment', 'worker_assignment'] as $key) {
            if (! is_array($safe[$key] ?? null)) {
                continue;
            }

            $nested = $safe[$key];
            if (is_array($nested['roomIds'] ?? null)) {
                $nested['roomIds'] = array_values(array_filter(
                    $nested['roomIds'],
                    static fn (mixed $roomId): bool => isset($ownedIds[(int) $roomId]),
                ));
            }
            foreach (['workerFinishedPropertyRooms', 'worker_finished_property_rooms'] as $roomsKey) {
                if (! is_array($nested[$roomsKey] ?? null)) {
                    continue;
                }
                $nested[$roomsKey] = array_values(array_filter(
                    $nested[$roomsKey],
                    static function (mixed $snapshot) use ($ownedIds, $ownedKeys, $ownedLabels): bool {
                        if (! is_array($snapshot)) {
                            return false;
                        }
                        if (is_numeric($snapshot['id'] ?? null)) {
                            return isset($ownedIds[(int) $snapshot['id']]);
                        }
                        $roomKey = $snapshot['roomKey'] ?? $snapshot['room_key'] ?? null;
                        if (is_string($roomKey) && mb_trim($roomKey) !== '') {
                            return isset($ownedKeys[mb_trim($roomKey)]);
                        }
                        $label = $snapshot['displayLabel'] ?? $snapshot['display_label'] ?? $snapshot['name'] ?? $snapshot['label'] ?? null;

                        return is_string($label) && mb_trim($label) !== '' && isset($ownedLabels[mb_trim($label)]);
                    },
                ));
            }
            $safe[$key] = $nested;
        }

        return $safe;
    }

    private function shouldScopeV2WorkerRooms(CleaningBooking $booking): bool
    {
        if ((int) $booking->number_of_workers <= 1
            || ! in_array((string) $booking->booking_kind, ['standard', 'open_time'], true)) {
            return false;
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];

        return $booking->isV2OperationalGroup()
            || array_key_exists('_time_period_v2', $details)
            || array_key_exists('_room_period_v2_approved_total', $details);
    }
}

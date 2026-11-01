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
use Modules\Cleaning\Services\CleaningBookingWorkerSessionVisibilityService;
use Modules\Cleaning\Support\WorkerBookingRequirementsPresenter;
use Modules\Cleaning\Support\WorkerPendingSpecialServicesPreview;

final class CleaningBookingShowController
{
    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingOperationalResource|JsonResponse
    {
        // Supervisor-managed work has a separate redacted read endpoint.
        if ($cleaning_booking->isSupervisedTeam()) {
            abort(404);
        }
        $worker = Auth::user()?->worker;
        if ($worker instanceof Worker && $this->shouldScopeV2WorkerRooms($cleaning_booking)) {
            $eligibleOffer = $this->isWorkerDispatchCandidate($cleaning_booking)
                && ((string) $cleaning_booking->booking_kind === 'standard'
                    || app(CleaningBookingWorkerSessionVisibilityService::class)
                        ->visibleSessions($cleaning_booking, $worker)->isNotEmpty());
            abort_unless($this->workerHasAcceptedAssignment($cleaning_booking, $worker) || $eligibleOffer, 404);

            return response()->json(['data' => $this->workerDiscoveryPayload($cleaning_booking, $worker)]);
        }
        if ($worker instanceof Worker && $this->isWorkerDispatchCandidate($cleaning_booking)) {
            return response()->json(['data' => $this->workerDiscoveryPayload($cleaning_booking, $worker)]);
        }

        $this->ensureWorkerCanViewBooking($cleaning_booking);

        return CleaningBookingOperationalResource::make($cleaning_booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms',
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

        // An offer the worker explicitly declined is no longer available to
        // that worker, even if a legacy worker_id still points to them.
        abort_if($booking->rejections()->where('worker_id', $worker->id)->exists(), 404, 'Booking not found.');

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
            ((int) $booking->number_of_workers === 1
                && (int) ($booking->worker_id ?? 0) === (int) $worker->id)
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
            'rooms',
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
            'urgencyLabel', 'cleaning_services', 'services', 'createdAt', 'updatedAt',
        ]);

        $scopeVisitRooms = $this->shouldScopeV2WorkerRooms($booking);
        $safe['roomRequirements'] = WorkerBookingRequirementsPresenter::summarize(
            $scopeVisitRooms
                ? $this->workerOwnedRoomProjection($booking, $worker)
                : $booking->rooms()->get()->map(fn ($room): array => ['id' => $room->id, 'roomKey' => $room->room_key, 'roomType' => $room->room_type, 'roomSize' => $room->room_size, 'displayLabel' => $room->display_label])->all(),
            $scopeVisitRooms ? null : (is_array($booking->property_details) ? $booking->property_details : null),
        );
        $safe['locationSummary'] = $booking->neighborhood_name;
        $safe['neighborhoodName'] = $booking->neighborhood_name;

        // A candidate may review the task description and selected time before
        // accepting an Open-Time offer. Keep full address, totals and other
        // property metadata redacted until acceptance.
        if ((string) $booking->booking_kind === 'open_time') {
            $safe['propertyDetails'] = [
                'notes' => mb_trim((string) data_get($booking->property_details, 'notes', '')),
            ];
            $safe['openTime'] = [
                'isOpenTime' => true,
                'requestedWorkerCount' => max(1, (int) $booking->number_of_workers),
                'expectedMaxMinutes' => max(15, (int) $booking->open_time_expected_max_minutes),
            ];
        }

        if ($this->isWorkerDispatchCandidate($booking)) {
            $safe['specialServicesPreview'] = WorkerPendingSpecialServicesPreview::forBooking($booking);
        }

        $acceptedSessions = $booking->sessions()
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

        $acceptedBookingWorker = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
        if ($acceptedSessions !== [] && (string) $booking->booking_kind === 'open_time') {
            $safe['sessions'] = $acceptedSessions;
        }
        if ($acceptedSessions !== [] || $acceptedBookingWorker) {
            // Supervisor contact information is available only after accepting this booking.
            if ($acceptedBookingWorker && is_array($payload['supervisorContact'] ?? null)) {
                $safe['supervisorContact'] = $payload['supervisorContact'];
            }
            $safe['roomTasks'] = $booking->rooms()->get()->map(static fn ($room): array => [
                    'id' => $room->id, 'roomKey' => $room->room_key,
                    'roomType' => $room->room_type, 'roomSize' => $room->room_size,
                    'displayLabel' => $room->display_label,
                ])->all();
            // Booking-level service details are available after acceptance.
            $safe['bookingBasePrice'] = (float) ($payload['bookingBasePrice'] ?? $booking->base_price ?? 0);
            $safe['address'] = $payload['address'] ?? ['fullAddress' => data_get($booking->property_details, 'address')];
            $safe['addressLatitude'] = $payload['addressLatitude'] ?? null;
            $safe['addressLongitude'] = $payload['addressLongitude'] ?? null;
            $safe = array_merge($safe, $this->acceptedWorkerOperationalDetails($booking, $worker, array_column($acceptedSessions, 'id')));
        }
        foreach (['workerOffer', 'worker_offer', 'myAssignment', 'worker_assignment'] as $key) {
            if (is_array($safe[$key] ?? null)) {
                unset($safe[$key]['roomIds']);
                if ($scopeVisitRooms) {
                    // Worker completion history can contain room/service details
                    // assigned to other workers, so omit these aggregates.
                    unset(
                        $safe[$key]['workerFinishedPropertyRooms'],
                        $safe[$key]['worker_finished_property_rooms'],
                        $safe[$key]['workerFinishedCleaningServices'],
                        $safe[$key]['worker_finished_cleaning_services'],
                    );
                }
            }
        }

        return $safe;
    }

    /**
     * Materials and kits belong to the booking. Special services must respect
     * any explicit worker assignment, and session-specific work remains scoped.
     *
     * @param  list<int>  $acceptedSessionIds
     * @return array<string, mixed>
     */
    private function acceptedWorkerOperationalDetails(CleaningBooking $booking, Worker $worker, array $acceptedSessionIds): array
    {
        $acceptedBookingWorker = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
        if ($acceptedSessionIds === [] && ! $acceptedBookingWorker) {
            return [];
        }

        $operational = (new CleaningBookingOperationalResource($booking))->toArray(request());
        $specialServices = array_values(array_filter(
            $operational['specialServices'],
            static fn (array $service): bool => ($service['assignedWorkerId'] === null || $service['assignedWorkerId'] === (int) $worker->id)
                && ($acceptedBookingWorker || array_intersect($service['sessionIds'], $acceptedSessionIds) !== []),
        ));

        return [
            'materials' => $operational['materials'],
            'materialKit' => $operational['materialKit'],
            'specialServices' => $specialServices,
        ];
    }

    private function workerHasAcceptedAssignment(CleaningBooking $booking, Worker $worker): bool
    {
        if ($booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists()) {
            return true;
        }

        return $booking->sessions()
            ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->exists();
    }

    private function workerOwnedRoomProjection(CleaningBooking $booking, Worker $worker): array
    {
        if (! $this->workerHasAcceptedAssignment($booking, $worker)) {
            return [];
        }

        return $booking->rooms()->get()->map(static fn ($room): array => [
            'id' => (int) $room->id,
            'roomKey' => $room->room_key,
            'roomType' => $room->room_type,
            'roomSize' => $room->room_size,
            'displayLabel' => $room->display_label,
        ])->all();
    }

    /**
     */
    private function shouldScopeV2WorkerRooms(CleaningBooking $booking): bool
    {
        if ((int) $booking->number_of_workers <= 1
            || ! in_array((string) $booking->booking_kind, ['standard', 'open_time'], true)) {
            return false;
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];

        return $booking->isV2OperationalGroup()
            || array_key_exists('_time_period_v2', $details);
    }
}

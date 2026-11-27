<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingWorkerScheduleService;
use Modules\Cleaning\Services\CleaningBookingWorkerSessionVisibilityService;
use Modules\Cleaning\Services\CleaningV3DailyBookingProjection;
use Modules\Cleaning\Services\CleaningV3OperationalAdmissionPolicy;
use Modules\Cleaning\Services\CleaningWorkerCustomerDataDisclosurePolicy;

final class SupervisedWorkerBookingController
{
    public function __invoke(Request $request, CleaningBooking $booking): JsonResponse
    {
        $worker = $request->user()?->worker;
        if ($booking->contract_version === 'v3') {
            abort_unless($worker instanceof Worker && $request->user()?->is_active, 404);

            return $this->dailyV3($request, $booking, $worker);
        }
        $isManagedStandardTeam = (string) $booking->booking_kind === 'standard'
            && (int) $booking->number_of_workers > 1
            && $booking->supervisorAssignments()->where('active_slot', 1)
                ->whereNull('released_at')->exists();
        if (! $worker || (! $booking->isSupervisedTeam() && ! $isManagedStandardTeam)) {
            abort(404);
        }

        $assignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->first();
        if (! $assignment) {
            abort(404);
        }

        $status = $booking->status?->value ?? (string) $booking->status;
        $canTravel = in_array($status, [
            'worker_assigned', 'awaiting_start_verification',
            'awaiting_worker_start_confirmation', 'in_progress',
            'awaiting_customer_completion', 'time_extension_requested',
        ], true);
        $membershipStatus = $assignment->status?->value ?? (string) $assignment->status;
        $canTravel = $canTravel && in_array(
            $membershipStatus,
            CleaningBookingWorkerAssignmentStatus::activeValues(),
            true,
        );
        $travelStarted = $assignment->started_travel_at !== null;
        $mayViewDestination = app(CleaningWorkerCustomerDataDisclosurePolicy::class)
            ->mayViewDestination($booking, $worker, $request);
        $neighborhoodLabel = app(CleaningWorkerCustomerDataDisclosurePolicy::class)
            ->neighborhoodLabel($booking);
        $arrived = $assignment->arrived_at !== null;
        $attendanceConfirmed = DB::table('cleaning_worker_attendance_proofs')
            ->where('cleaning_booking_id', $booking->id)
            ->where('worker_assignment_id', $assignment->id)
            ->whereNotNull('confirmed_at')->exists();
        $canIssueAttendanceCode = (int) $booking->number_of_workers > 1
            && $arrived && ! $attendanceConfirmed
            && in_array($status, ['worker_assigned', 'awaiting_start_verification'], true);

        $booking->load([
            'rooms',
            'roomSchedules.room',
            'specialServices.items',
            'supervisorAssignments.supervisor',
        ]);
        $supervisor = $booking->supervisorAssignments
            ->first(fn ($item) => $item->released_at === null && (int) $item->active_slot === 1);

        // Booking-level scope only: periods are not assigned to individual
        // workers. Never serialize materials, prices, or per-worker room owners.
        $roomTasks = $booking->rooms->map(fn ($room) => [
            'id' => $room->id,
            'key' => $room->room_key,
            'title' => $room->display_label,
            'roomType' => $room->room_type,
            'roomSize' => $room->room_size,
            'cleaningMode' => is_array($booking->property_details['room_cleaning_modes'] ?? null)
                ? ($booking->property_details['room_cleaning_modes'][$room->room_key] ?? null)
                : null,
        ]);
        $tasks = $booking->roomSchedules->isNotEmpty()
            ? $booking->roomSchedules->map(fn ($schedule): array => [
                'id' => $schedule->room?->id,
                'key' => $schedule->room?->client_key,
                'title' => $schedule->room?->display_label,
                'roomType' => $schedule->room?->room_type,
                'roomSize' => $schedule->room?->room_size,
                'cleaningMode' => $schedule->room?->cleaning_mode,
                'scheduledDate' => $schedule->starts_at?->setTimezone($schedule->timezone)->toDateString(),
                'scheduledTime' => $schedule->starts_at?->setTimezone($schedule->timezone)->format('H:i'),
                'estimatedWorkMinutes' => $schedule->work_minutes,
            ])
            : $roomTasks;

        return response()->json(['data' => [
            'id' => $booking->id,
            'bookingNumber' => $booking->booking_number,
            'serviceType' => 'supervised_team',
            'status' => $booking->status?->value ?? (string) $booking->status,
            'scheduledDate' => $booking->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $booking->scheduled_time,
            'neighborhoodName' => $neighborhoodLabel,
            // An accepted worker may navigate to the customer only after their
            // own start-travel action. Never disclose coordinates in advance.
            'destination' => $mayViewDestination && $booking->address_latitude !== null
                && $booking->address_longitude !== null ? [
                    'latitude' => (float) $booking->address_latitude,
                    'longitude' => (float) $booking->address_longitude,
                    'label' => data_get($booking->property_details, 'full_address')
                        ?: data_get($booking->property_details, 'address')
                        ?: $neighborhoodLabel,
                ] : null,
            'propertyType' => $booking->property_type,
            'propertyDetails' => [
                'eventType' => data_get($booking->property_details, 'event_type'),
                'hours' => data_get($booking->property_details, 'hours'),
            ],
            'supervisor' => $supervisor ? [
                'id' => $supervisor->supervisor_user_id,
                'name' => $supervisor->supervisor?->name,
                'phone' => $supervisor->supervisor?->phone,
            ] : null,
            'myMembership' => [
                'assignmentId' => $assignment->id,
                'status' => $membershipStatus,
            ],
            'myAttendance' => [
                'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
                'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
                'canStartTravel' => $canTravel && ! $travelStarted && ! $arrived,
                'canArrive' => $canTravel && $travelStarted && ! $arrived,
                'attendanceConfirmed' => $attendanceConfirmed,
                'canIssueAttendanceCode' => $canIssueAttendanceCode,
            ],
            'scopeRooms' => $roomTasks->values(),
            'tasks' => $tasks->values(),
            'specializedServices' => $booking->specialServices->map(fn ($service) => [
                'id' => $service->id,
                'name' => $service->service_name,
                'quantity' => $service->quantity,
                'dirtinessLevel' => $service->dirtiness_level,
                'notes' => null,
                'items' => $service->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name ?? null,
                ])->values(),
            ])->values(),
            'allowedActions' => in_array($booking->status?->value ?? (string) $booking->status,
                ['completed', 'cancelled'], true) ? ['view'] : ['view', 'sos'],
        ]])->header('Cache-Control', 'private, no-store');
    }

    /** V3 never uses the legacy parent membership/attendance proof namespace. */
    private function dailyV3(Request $request, CleaningBooking $booking, Worker $worker): JsonResponse
    {
        $projection = app(CleaningV3DailyBookingProjection::class);
        $visit = $projection->execution($booking);
        abort_unless($visit !== null
            && $visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            && app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking, $visit)
            && app(CleaningBookingWorkerSessionVisibilityService::class)->canViewSession($booking, $visit, $worker), 404);
        // An eligible offer is not an accepted team membership. No legacy
        // parent assignment or released execution can grant this detail.
        $assignment = $visit->workerAssignments()->where('worker_id', $worker->id)
            ->whereNull('released_at')->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->firstOrFail();
        $requested = $request->query('sessionId');
        abort_if($requested !== null && (! is_scalar($requested) || (string) $requested !== (string) $visit->id), 404);

        $schedule = app(CleaningBookingWorkerScheduleService::class)->present($booking, $worker);
        $daily = $projection->present($booking, $request->user(), $schedule);
        abort_unless($daily !== null && (int) $daily['executionId'] === (int) $visit->id
            && count($schedule['sessions'] ?? []) === 1
            && (int) ($schedule['sessions'][0]['sessionId'] ?? 0) === (int) $visit->id, 404);
        $disclosure = app(CleaningWorkerCustomerDataDisclosurePolicy::class);
        $neighborhood = $disclosure->neighborhoodLabel($booking);
        $destination = $requested !== null && $disclosure->mayViewDestination($booking, $worker, $request)
            && $booking->address_latitude !== null && $booking->address_longitude !== null
            ? ['latitude' => (float) $booking->address_latitude, 'longitude' => (float) $booking->address_longitude,
                'label' => data_get($booking->property_details, 'full_address') ?: data_get($booking->property_details, 'address') ?: $neighborhood]
            : null;
        $supervisor = $booking->supervisorAssignments()->where('active_slot', 1)->whereNull('released_at')
            ->with('supervisor')->first();

        return response()->json(['data' => [
            'id' => (int) $booking->id,
            'bookingNumber' => $booking->booking_number,
            'contractVersion' => 3,
            'serviceType' => 'supervised_team',
            'status' => $daily['status'],
            'scheduledDate' => $daily['scheduledDate'],
            'scheduledTime' => $daily['scheduledTime'],
            'neighborhoodName' => $neighborhood,
            'destination' => $destination,
            'propertyType' => $booking->property_type,
            'supervisor' => $supervisor?->supervisor?->is_active ? [
                'id' => (int) $supervisor->supervisor_user_id,
                'name' => $supervisor->supervisor->name,
                'phone' => $supervisor->supervisor->phone,
            ] : null,
            'myMembership' => [
                'executionId' => (int) $visit->id,
                'sessionAssignmentId' => (int) $assignment->id,
                'status' => $assignment->status->value,
            ],
            'myAttendance' => [
                'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
                'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
                'attendanceConfirmed' => $assignment->start_approved_at !== null,
                // Older clients would call parent-level mutations using these
                // flags. Keep them read-only; V3 consumers use dailyBooking.
                'canStartTravel' => false,
                'canArrive' => false,
                'canIssueAttendanceCode' => false,
            ],
            'allowedActions' => ['view'],
            'dailyBooking' => $daily,
            ...app(\Modules\Cleaning\Services\CleaningV3WorkRequirements::class)->forWorker($booking, $visit, $worker),
        ]])->header('Cache-Control', 'private, no-store');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class SupervisedWorkerBookingController
{
    public function __invoke(Request $request, CleaningBooking $booking): JsonResponse
    {
        $worker = $request->user()?->worker;
        if (! $worker || ! $booking->isSupervisedTeam()) {
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
        $arrived = $assignment->arrived_at !== null;

        $booking->load([
            'rooms',
            'roomPeriods',
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
        $roomTitles = [
            'bedroom' => 'غرفة نوم', 'bathroom' => 'حمام', 'toilet' => 'مرحاض',
            'kitchen' => 'مطبخ', 'living_room' => 'غرفة معيشة',
            'balcony' => 'شرفة', 'corridor' => 'ممر', 'shed' => 'مستودع',
        ];
        $tasks = $booking->roomPeriods->isNotEmpty()
            ? $booking->roomPeriods->map(fn ($period) => [
                'id' => $period->id,
                'key' => $period->client_key,
                'title' => $roomTitles[$period->room_type] ?? 'غرفة',
                'roomType' => $period->room_type,
                'roomSize' => $period->room_size,
                'cleaningMode' => $period->cleaning_mode,
                'notes' => $period->notes,
                'scheduledDate' => $period->scheduled_date?->format('Y-m-d'),
                'scheduledTime' => $period->scheduled_time,
                'estimatedWorkMinutes' => $period->estimated_work_minutes,
            ])
            : $roomTasks;

        return response()->json(['data' => [
            'id' => $booking->id,
            'bookingNumber' => $booking->booking_number,
            'serviceType' => 'supervised_team',
            'status' => $booking->status?->value ?? (string) $booking->status,
            'scheduledDate' => $booking->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $booking->scheduled_time,
            'neighborhoodName' => $booking->neighborhood_name,
            'propertyType' => $booking->property_type,
            'propertyDetails' => [
                'customService' => data_get($booking->property_details, 'custom_service'),
                'eventType' => data_get($booking->property_details, 'event_type'),
                'hours' => data_get($booking->property_details, 'hours'),
                'specialRequirement' => data_get($booking->property_details, 'special_requirement'),
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
            ],
            'scopeRooms' => $roomTasks->values(),
            'tasks' => $tasks->values(),
            'specializedServices' => $booking->specialServices->map(fn ($service) => [
                'id' => $service->id,
                'name' => $service->service_name,
                'quantity' => $service->quantity,
                'dirtinessLevel' => $service->dirtiness_level,
                'notes' => $service->notes,
                'items' => $service->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name ?? null,
                ])->values(),
            ])->values(),
            'allowedActions' => in_array($booking->status?->value ?? (string) $booking->status,
                ['completed', 'cancelled'], true) ? ['view'] : ['view', 'sos'],
        ]]);
    }
}

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

        $booking->load([
            'rooms',
            'specialServices.items',
            'supervisorAssignments.supervisor',
        ]);
        $supervisor = $booking->supervisorAssignments
            ->first(fn ($item) => $item->released_at === null && (int) $item->active_slot === 1);

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
            ] : null,
            'myMembership' => [
                'assignmentId' => $assignment->id,
                'status' => $assignment->status?->value ?? (string) $assignment->status,
            ],
            'tasks' => $booking->rooms->map(fn ($room) => [
                'id' => $room->id,
                'key' => $room->room_key,
                'title' => $room->display_label,
                'roomType' => $room->room_type,
                'roomSize' => $room->room_size,
                'cleaningMode' => (is_array($booking->property_details['room_cleaning_modes'] ?? null)
                    ? ($booking->property_details['room_cleaning_modes'][$room->room_key] ?? null)
                    : null),
            ])->values(),
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
            'allowedActions' => ['view'],
        ]]);
    }
}

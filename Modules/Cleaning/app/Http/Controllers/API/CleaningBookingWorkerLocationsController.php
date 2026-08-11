<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningBookingWorkerLocationsController
{
    public function __invoke(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $user = $request->user();
        $worker = $user?->worker;

        $isCustomer = (int) $cleaning_booking->customer_id === (int) $user?->id;
        $isAssignedWorker = $worker instanceof Worker && (
            (int) $cleaning_booking->worker_id === (int) $worker->id
            || $cleaning_booking->workerAssignments()
                ->where('worker_id', $worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->exists()
        );

        abort_unless($isCustomer || $isAssignedWorker, 403, 'You cannot view worker locations for this booking.');

        $assignments = $cleaning_booking->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->with('worker.user')
            ->orderBy('id')
            ->get();

        $locations = $assignments
            ->map(fn (CleaningBookingWorkerAssignment $assignment): array => $this->assignmentPayload($assignment))
            ->values()
            ->all();

        if ($locations === [] && $cleaning_booking->worker_id !== null) {
            $cleaning_booking->loadMissing('worker.user');
            $legacyWorker = $cleaning_booking->worker;
            $legacyLocationUpdatedAt = $cleaning_booking->getAttribute('worker_location_updated_at');

            $locations[] = [
                'assignmentId' => null,
                'workerId' => (int) $cleaning_booking->worker_id,
                'status' => $cleaning_booking->status?->value ?? (string) $cleaning_booking->status,
                'startedTravelAt' => $cleaning_booking->started_travel_at?->toIso8601String(),
                'arrivedAt' => $cleaning_booking->arrived_at?->toIso8601String(),
                'latitude' => $cleaning_booking->last_worker_latitude !== null
                    ? (float) $cleaning_booking->last_worker_latitude
                    : null,
                'longitude' => $cleaning_booking->last_worker_longitude !== null
                    ? (float) $cleaning_booking->last_worker_longitude
                    : null,
                'updatedAt' => $legacyLocationUpdatedAt === null
                    ? null
                    : Carbon::parse((string) $legacyLocationUpdatedAt)->toIso8601String(),
                'worker' => $legacyWorker === null ? null : [
                    'id' => $legacyWorker->id,
                    'name' => $legacyWorker->user?->name ?? $legacyWorker->first_name,
                    'phone' => $legacyWorker->user?->phone,
                    'averageRating' => $legacyWorker->average_rating !== null
                        ? (float) $legacyWorker->average_rating
                        : null,
                    'avatarUrl' => null,
                ],
            ];
        }

        return response()->json([
            'data' => $locations,
            'meta' => [
                'bookingId' => $cleaning_booking->id,
                'updatedAt' => now()->toIso8601String(),
            ],
        ]);
    }

    private function assignmentPayload(CleaningBookingWorkerAssignment $assignment): array
    {
        $worker = $assignment->worker;
        $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;

        return [
            'assignmentId' => $assignment->id,
            'workerId' => $assignment->worker_id,
            'status' => $status,
            'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
            'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
            'latitude' => $assignment->last_latitude !== null ? (float) $assignment->last_latitude : null,
            'longitude' => $assignment->last_longitude !== null ? (float) $assignment->last_longitude : null,
            'updatedAt' => $assignment->location_updated_at?->toIso8601String(),
            'worker' => $worker === null ? null : [
                'id' => $worker->id,
                'name' => $worker->user?->name ?? $worker->first_name,
                'phone' => $worker->user?->phone,
                'averageRating' => $worker->average_rating !== null ? (float) $worker->average_rating : null,
                'avatarUrl' => null,
            ],
        ];
    }
}

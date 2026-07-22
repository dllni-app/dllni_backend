<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Http\Requests\CleaningBookingLocationRequest;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningWorkerLocationHistory;

final class CleaningBookingLocationController
{
    public function __invoke(CleaningBookingLocationRequest $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $worker = $request->user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $latitude = (float) $request->validated('latitude');
        $longitude = (float) $request->validated('longitude');
        $recordedAt = now();

        $assignment = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $cleaning_booking->id)
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->first();

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            if ($assignment->started_travel_at === null || $assignment->arrived_at !== null) {
                return $this->ignoredResponse();
            }

            $assignment->forceFill([
                'last_latitude' => $latitude,
                'last_longitude' => $longitude,
                'location_updated_at' => $recordedAt,
            ])->save();

            $this->recordHistory(
                booking: $cleaning_booking,
                worker: $worker,
                assignment: $assignment,
                latitude: $latitude,
                longitude: $longitude,
                recordedAt: $recordedAt,
            );

            BroadcastAfterResponse::send(new WorkerLocationUpdated(
                $cleaning_booking->id,
                $latitude,
                $longitude,
                $worker->id,
            ));

            return $this->successResponse($recordedAt->toIso8601String());
        }

        if ((int) $cleaning_booking->worker_id !== (int) $worker->id) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if (
            $cleaning_booking->status !== CleaningBookingStatus::WorkerAssigned
            || $cleaning_booking->started_travel_at === null
            || $cleaning_booking->arrived_at !== null
        ) {
            return $this->ignoredResponse();
        }

        $cleaning_booking->forceFill([
            'last_worker_latitude' => $latitude,
            'last_worker_longitude' => $longitude,
            'worker_location_updated_at' => $recordedAt,
        ])->save();

        $this->recordHistory(
            booking: $cleaning_booking,
            worker: $worker,
            assignment: null,
            latitude: $latitude,
            longitude: $longitude,
            recordedAt: $recordedAt,
        );

        BroadcastAfterResponse::send(new WorkerLocationUpdated(
            $cleaning_booking->id,
            $latitude,
            $longitude,
            $worker->id,
        ));

        return $this->successResponse($recordedAt->toIso8601String());
    }

    private function recordHistory(
        CleaningBooking $booking,
        Worker $worker,
        ?CleaningBookingWorkerAssignment $assignment,
        float $latitude,
        float $longitude,
        mixed $recordedAt,
    ): void {
        CleaningWorkerLocationHistory::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'assignment_id' => $assignment?->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $recordedAt,
        ]);
    }

    private function successResponse(string $updatedAt): JsonResponse
    {
        return response()->json([
            'data' => [
                'ok' => true,
                'ignored' => false,
                'updatedAt' => $updatedAt,
            ],
        ]);
    }

    private function ignoredResponse(): JsonResponse
    {
        return response()->json([
            'data' => [
                'ok' => true,
                'ignored' => true,
            ],
        ]);
    }
}

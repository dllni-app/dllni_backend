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

final class CleaningBookingLocationController
{
    public function __invoke(CleaningBookingLocationRequest $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $worker = $request->user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $assignment = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $cleaning_booking->id)
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->first();

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            if ($assignment->started_travel_at === null || $assignment->arrived_at !== null) {
                return $this->ignoredResponse();
            }

            BroadcastAfterResponse::send(new WorkerLocationUpdated(
                $cleaning_booking->id,
                (float) $request->validated('latitude'),
                (float) $request->validated('longitude'),
                $worker->id,
            ));

            return $this->successResponse();
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

        BroadcastAfterResponse::send(new WorkerLocationUpdated(
            $cleaning_booking->id,
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
            $worker->id,
        ));

        return $this->successResponse();
    }

    private function successResponse(): JsonResponse
    {
        return response()->json([
            'data' => [
                'ok' => true,
                'ignored' => false,
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

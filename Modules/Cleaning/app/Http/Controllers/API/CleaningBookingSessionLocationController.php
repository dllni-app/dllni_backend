<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Http\Requests\CleaningBookingLocationRequest;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionLocationController
{
    public function __invoke(
        CleaningBookingLocationRequest $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $worker = $request->user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        if ((int) $cleaning_booking_session->cleaning_booking_id !== (int) $cleaning_booking->id) {
            abort(404, 'Session does not belong to this booking.');
        }

        $assignment = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $cleaning_booking_session->id)
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->first();

        if (! $assignment instanceof CleaningBookingSessionWorkerAssignment) {
            abort(403, 'Worker is not assigned to this session.');
        }

        if ($assignment->started_travel_at === null || $assignment->arrived_at !== null) {
            return $this->ignoredResponse((int) $cleaning_booking_session->id);
        }

        $recordedAt = now();
        $latitude = (float) $request->validated('latitude');
        $longitude = (float) $request->validated('longitude');

        $assignment->forceFill([
            'last_latitude' => $latitude,
            'last_longitude' => $longitude,
            'location_updated_at' => $recordedAt,
        ])->save();

        BroadcastAfterResponse::send(new WorkerLocationUpdated(
            (int) $cleaning_booking->id,
            $latitude,
            $longitude,
            (int) $worker->id,
        ));

        return response()->json([
            'data' => [
                'ok' => true,
                'ignored' => false,
                'bookingId' => (int) $cleaning_booking->id,
                'sessionId' => (int) $cleaning_booking_session->id,
                'updatedAt' => $recordedAt->toIso8601String(),
            ],
        ]);
    }

    private function ignoredResponse(int $sessionId): JsonResponse
    {
        return response()->json([
            'data' => [
                'ok' => true,
                'ignored' => true,
                'sessionId' => $sessionId,
            ],
        ]);
    }
}

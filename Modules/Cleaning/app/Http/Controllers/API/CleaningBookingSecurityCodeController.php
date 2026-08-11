<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingService;
use Modules\Cleaning\Services\CleaningBookingWorkerSecurityCodeService;

final class CleaningBookingSecurityCodeController
{
    public function __invoke(
        CleaningBooking $cleaning_booking,
        CleaningBookingWorkerSecurityCodeService $service,
        CleaningBookingService $bookingService,
    ): JsonResponse {
        $this->ensureWorkerCanActOnBooking($cleaning_booking);

        try {
            // The worker app requests the security code when it enters the map
            // after "I'm on the way". Treat that request as the arrival step so
            // the customer booking moves to awaiting_start_verification and the
            // customer app receives the existing realtime arrival/verification
            // events instead of returning the old arrival validation error.
            $this->markWorkerArrivedWhenNeeded($cleaning_booking, $bookingService);

            $generated = $service->issueForCurrentWorker($cleaning_booking->fresh());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'status' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'message' => __('Security code generated successfully.'),
            'data' => [
                'securityCode' => $generated['securityCode'],
                'expiresAt' => $generated['expiresAt'],
            ],
        ]);
    }

    private function markWorkerArrivedWhenNeeded(
        CleaningBooking $booking,
        CleaningBookingService $bookingService,
    ): void {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            throw new InvalidArgumentException('User must have an associated worker.');
        }

        $assignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->first();

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            if ($assignment->arrived_at === null) {
                $bookingService->arrive($booking);
            }

            return;
        }

        if ((int) $booking->worker_id === (int) $worker->id && $booking->arrived_at === null) {
            $bookingService->arrive($booking);
        }
    }

    private function ensureWorkerCanActOnBooking(CleaningBooking $booking): void
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && (int) $booking->worker_id !== (int) $worker->id && ! $hasWorkerAssignment) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if ($booking->worker_id === null && ! $hasWorkerAssignment) {
            abort(403, 'Booking must be assigned to worker for this action.');
        }
    }
}

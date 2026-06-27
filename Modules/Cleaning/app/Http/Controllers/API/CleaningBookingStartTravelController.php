<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Throwable;

final class CleaningBookingStartTravelController
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    /** @throws Throwable */
    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking);

        $fromStatus = (string) $cleaning_booking->status->value;

        try {
            $booking = DB::transaction(function () use ($cleaning_booking): CleaningBooking {
                if ($cleaning_booking->status !== CleaningBookingStatus::WorkerAssigned) {
                    throw new InvalidArgumentException('Booking cannot start travel in current status.');
                }

                $cleaning_booking->update(['started_travel_at' => now()]);

                return $cleaning_booking->fresh();
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        $this->dispatchTrackingUpdate($booking);
        $this->lifecycleNotifications->notifyCustomer(
            booking: $booking,
            canonicalType: 'cleaning.booking.worker_started_travel',
            action: 'worker_started_travel',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $booking->started_travel_at?->toIso8601String() ?? $booking->updated_at?->toIso8601String(),
        );

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
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

        if ($booking->worker_id !== null && $booking->worker_id !== $worker->id && ! $hasWorkerAssignment) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if ($booking->worker_id === null && ! $hasWorkerAssignment) {
            abort(403, 'Booking must be assigned to worker for this action.');
        }
    }

    private function loadBookingDetails(CleaningBooking $booking): CleaningBooking
    {
        return $booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus ? $booking->status->value : (string) $booking->status;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $status,
            'statusLabel' => $booking->status instanceof CleaningBookingStatus ? $booking->status->label() : null,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'acceptedWorkers' => $booking->acceptedWorkerCount(),
            'remainingWorkers' => $booking->remainingWorkerCount(),
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isTeamFulfilled' => $booking->isTeamFulfilled(),
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'timerStoppedAt' => $booking->work_finished_at?->toIso8601String(),
            'isTimerRunning' => $status === CleaningBookingStatus::InProgress->value,
            'suspendedMessage' => null,
            'workerCompletionMessage' => $booking->worker_completion_message,
            'customerCompletionRejectionMessage' => $booking->customer_completion_rejection_message,
            'completionRejectedAt' => $booking->completion_rejected_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }
}

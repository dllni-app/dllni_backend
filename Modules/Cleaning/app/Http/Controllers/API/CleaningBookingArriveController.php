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
use Modules\Cleaning\Events\CleaningOrderAwaitingStartVerification;
use Modules\Cleaning\Events\WorkerArrived;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class CleaningBookingArriveController
{
    /** @throws Throwable */
    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking);

        try {
            $booking = DB::transaction(function () use ($cleaning_booking): CleaningBooking {
                $worker = Auth::user()?->worker;
                if (! $worker instanceof Worker) {
                    throw new InvalidArgumentException('User must have an associated worker.');
                }

                $lockedBooking = CleaningBooking::query()
                    ->whereKey($cleaning_booking->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $assignment = CleaningBookingWorkerAssignment::query()
                    ->where('cleaning_booking_id', $lockedBooking->id)
                    ->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->lockForUpdate()
                    ->first();

                if ($assignment instanceof CleaningBookingWorkerAssignment) {
                    $isTeamBooking = max(1, (int) ($lockedBooking->number_of_workers ?? 1)) > 1;

                    if ($isTeamBooking) {
                        if (in_array($lockedBooking->status, [
                            CleaningBookingStatus::Cancelled,
                            CleaningBookingStatus::Completed,
                            CleaningBookingStatus::UnderDispute,
                        ], true)) {
                            throw new InvalidArgumentException('Booking must be ready to start before marking arrival.');
                        }
                    } elseif (! in_array($lockedBooking->status, [
                        CleaningBookingStatus::WorkerAssigned,
                        CleaningBookingStatus::AwaitingStartVerification,
                        CleaningBookingStatus::AwaitingWorkerStartConfirmation,
                        CleaningBookingStatus::InProgress,
                    ], true)) {
                        throw new InvalidArgumentException('Booking must be ready to start before marking arrival.');
                    }

                    $startedTravelAt = $assignment->started_travel_at ?? $lockedBooking->started_travel_at;
                    if ($startedTravelAt === null) {
                        throw new InvalidArgumentException('Worker must have started travel before marking arrival.');
                    }

                    $arrivedAt = $assignment->arrived_at ?? now();

                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
                        'started_travel_at' => $startedTravelAt,
                        'arrived_at' => $arrivedAt,
                    ])->save();

                    $updates = [];
                    if ($lockedBooking->status !== CleaningBookingStatus::AwaitingWorkerStartConfirmation) {
                        $updates['status'] = CleaningBookingStatus::AwaitingStartVerification;
                    }
                    if ($lockedBooking->started_travel_at === null) {
                        $updates['started_travel_at'] = $startedTravelAt;
                    }
                    if ($lockedBooking->arrived_at === null) {
                        $updates['arrived_at'] = $arrivedAt;
                    }
                    if ($updates !== []) {
                        $lockedBooking->forceFill($updates)->save();
                    }

                    return $this->freshBooking($lockedBooking);
                }

                if ($lockedBooking->status !== CleaningBookingStatus::WorkerAssigned) {
                    throw new InvalidArgumentException('Booking must be ready to start before marking arrival.');
                }

                if ((int) $lockedBooking->worker_id !== (int) $worker->id) {
                    throw new InvalidArgumentException('Worker must accept the booking before marking arrival.');
                }

                if ($lockedBooking->started_travel_at === null) {
                    throw new InvalidArgumentException('Worker must have started travel before marking arrival.');
                }

                $lockedBooking->update([
                    'status' => CleaningBookingStatus::AwaitingStartVerification,
                    'arrived_at' => now(),
                ]);

                return $this->freshBooking($lockedBooking);
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        BroadcastAfterResponse::send(new WorkerArrived($booking->id, (string) $booking->arrived_at?->toIso8601String()));
        BroadcastAfterResponse::send(new CleaningOrderAwaitingStartVerification(
            $booking->id,
            $booking->customer_id,
            $booking->worker_id,
            (string) $booking->status?->value,
            null,
        ));
        $this->dispatchTrackingUpdate($booking);

        return CleaningBookingResource::make($this->loadBookingDetails($booking));
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

    private function freshBooking(CleaningBooking $booking): CleaningBooking
    {
        return $booking->fresh([
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

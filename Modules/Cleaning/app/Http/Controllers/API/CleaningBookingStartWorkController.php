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
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingPriceAdjustmentService;
use Modules\Cleaning\Services\CleaningBookingService;

final class CleaningBookingStartWorkController
{
    public function __construct(
        private readonly CleaningBookingService $cleaningBookingService,
        private readonly CleaningBookingPriceAdjustmentService $priceAdjustmentService,
    ) {}

    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureCurrentWorkerCanStart($cleaning_booking);

        try {
            $this->priceAdjustmentService->assertNoPendingRequestBeforeStart($cleaning_booking);
            $booking = $this->shouldUseTeamStartFlow($cleaning_booking)
                ? $this->startTeamWorker($cleaning_booking)
                : $this->cleaningBookingService->startWork($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make($this->loadBookingDetails($booking));
    }

    private function ensureCurrentWorkerCanStart(CleaningBooking $booking): void
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $hasAcceptedAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && (int) $booking->worker_id !== (int) $worker->id && ! $hasAcceptedAssignment) {
            abort(403, 'Booking is not available for this worker.');
        }

        if ($booking->worker_id === null && ! $hasAcceptedAssignment) {
            abort(403, 'Booking must be assigned before this action.');
        }
    }

    private function shouldUseTeamStartFlow(CleaningBooking $booking): bool
    {
        return max(1, (int) ($booking->number_of_workers ?? 1)) > 1
            && $booking->workerAssignments()->exists();
    }

    private function startTeamWorker(CleaningBooking $booking): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking): CleaningBooking {
            $worker = Auth::user()?->worker;
            if (! $worker instanceof Worker) {
                throw new InvalidArgumentException('User must have an associated worker.');
            }

            $lockedBooking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedBooking->status, [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
                CleaningBookingStatus::InProgress,
            ], true)) {
                throw new InvalidArgumentException('Booking must be ready to start before approving work start.');
            }

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('worker_id', $worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof CleaningBookingWorkerAssignment) {
                throw new InvalidArgumentException('Worker must accept the booking before approving start.');
            }

            $assignmentStatus = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                ? $assignment->status->value
                : (string) $assignment->status;

            if ($assignmentStatus === CleaningBookingWorkerAssignmentStatus::InProgress->value) {
                return $this->freshBooking($lockedBooking);
            }

            if ($assignment->arrived_at === null) {
                throw new InvalidArgumentException('Worker must arrive before approving work start.');
            }

            $this->assertSecurityCodeVerified($lockedBooking);

            $startedAt = now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                'start_approved_at' => $assignment->start_approved_at ?? $startedAt,
                'work_started_at' => $assignment->work_started_at ?? $startedAt,
            ])->save();

            $requiredWorkers = max(1, (int) ($lockedBooking->number_of_workers ?? 1));
            $startedWorkers = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('status', CleaningBookingWorkerAssignmentStatus::InProgress->value)
                ->whereNotNull('work_started_at')
                ->lockForUpdate()
                ->count();

            if ($startedWorkers >= $requiredWorkers) {
                $lockedBooking->forceFill([
                    'status' => CleaningBookingStatus::InProgress,
                    'work_started_at' => $lockedBooking->work_started_at ?? $startedAt,
                ])->save();
            } else {
                $lockedBooking->forceFill([
                    'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
                    'work_started_at' => null,
                ])->save();
            }

            return $this->freshBooking($lockedBooking);
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    private function assertSecurityCodeVerified(CleaningBooking $booking): void
    {
        $securityCode = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $securityCode || $securityCode->consumed_at === null) {
            throw new InvalidArgumentException('Customer must verify the security code before work can start.');
        }
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

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
            'workerCompletionMessage' => $booking->worker_completion_message,
            'customerCompletionRejectionMessage' => $booking->customer_completion_rejection_message,
            'completionRejectedAt' => $booking->completion_rejected_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
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
}

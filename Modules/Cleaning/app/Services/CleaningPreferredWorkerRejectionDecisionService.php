<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Observers\CleaningBookingObserver;

final class CleaningPreferredWorkerRejectionDecisionService
{
    public const DECISION_CONVERT_TO_OPEN = 'convert_to_open';

    public const DECISION_CANCEL = 'cancel';

    private const CUSTOMER_DECLINED_REASON = 'Customer declined converting to an open cleaning request after the preferred worker rejected the booking.';

    /**
     * @return Collection<int, CleaningBooking>
     */
    public function pendingForCustomer(int $customerId): Collection
    {
        return CleaningBooking::query()
            ->where('customer_id', $customerId)
            ->where('status', CleaningBookingStatus::Pending->value)
            ->where(
                'preferred_worker_rejection_decision_status',
                CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_PENDING
            )
            ->with([
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'timeWarnings',
                'disputes',
                'addons',
                'billingPolicy',
            ])
            ->orderBy('preferred_worker_rejected_at')
            ->orderBy('id')
            ->get();
    }

    public function decide(CleaningBooking $booking, int $customerId, string $decision): CleaningBooking
    {
        return match ($decision) {
            self::DECISION_CONVERT_TO_OPEN => $this->convertToOpen($booking, $customerId),
            self::DECISION_CANCEL => $this->cancelWithoutFee($booking, $customerId),
            default => throw ValidationException::withMessages([
                'decision' => ['Invalid preferred worker rejection decision.'],
            ]),
        };
    }

    private function convertToOpen(CleaningBooking $booking, int $customerId): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $customerId): CleaningBooking {
            $booking = $this->lockedCustomerBooking($booking, $customerId);
            $this->ensurePendingDecision($booking);

            CleaningBookingObserver::withoutLifecycleUpdateNotificationsFor((int) $booking->id, function () use ($booking): void {
                $booking->forceFill([
                    'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
                    'preferred_worker_id' => null,
                    'converted_from_preferred_worker' => true,
                    'converted_from_preferred_worker_at' => now(),
                    'preferred_worker_rejection_decision_status' => CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CONVERTED_TO_OPEN,
                    'preferred_worker_rejection_decided_at' => now(),
                ])->save();

                $booking->rooms()->update([
                    'planned_preferred_worker_id' => null,
                ]);
            });

            return $this->freshBooking($booking);
        });

        NotifyEligibleWorkersNewOrderJob::dispatch($updated->id);
        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    private function cancelWithoutFee(CleaningBooking $booking, int $customerId): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $customerId): CleaningBooking {
            $booking = $this->lockedCustomerBooking($booking, $customerId);
            $this->ensurePendingDecision($booking);

            $booking->forceFill([
                'status' => CleaningBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_role' => 'customer',
                'cancellation_fee' => 0,
                'cancellation_reason' => self::CUSTOMER_DECLINED_REASON,
                'preferred_worker_rejection_decision_status' => CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CANCELLED,
                'preferred_worker_rejection_decided_at' => now(),
            ])->save();

            return $this->freshBooking($booking);
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    private function lockedCustomerBooking(CleaningBooking $booking, int $customerId): CleaningBooking
    {
        return CleaningBooking::query()
            ->whereKey($booking->id)
            ->where('customer_id', $customerId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensurePendingDecision(CleaningBooking $booking): void
    {
        if (! $booking->requiresPreferredWorkerRejectionDecision()) {
            throw ValidationException::withMessages([
                'decision' => ['This preferred worker rejection decision is no longer pending.'],
            ]);
        }
    }

    private function freshBooking(CleaningBooking $booking): CleaningBooking
    {
        return $booking->fresh([
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $booking->status?->value,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'convertedFromPreferredWorker' => (bool) ($booking->converted_from_preferred_worker ?? false),
            'requiresPreferredWorkerRejectionDecision' => $booking->requiresPreferredWorkerRejectionDecision(),
            'preferredWorkerRejectionDecisionStatus' => $booking->preferred_worker_rejection_decision_status,
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
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningBookingWorkerCompletionService
{
    public function __construct(
        private readonly CleaningExtendedTimePricingService $extendedTimePricing,
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
        private readonly DepositService $depositService,
    ) {}

    public function confirm(CleaningBooking $booking, ?int $workerId = null, ?int $assignmentId = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;
        $decisionWorkerId = null;
        $decisionAssignmentId = null;

        $updated = DB::transaction(function () use ($booking, $workerId, $assignmentId, &$decisionWorkerId, &$decisionAssignmentId): CleaningBooking {
            $lockedBooking = $this->lockBooking($booking);
            $assignment = $this->resolvePendingCompletionAssignment($lockedBooking, $workerId, $assignmentId, true);

            if (! $assignment instanceof CleaningBookingWorkerAssignment) {
                if ($lockedBooking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
                    throw ValidationException::withMessages([
                        'status' => ['Order is not waiting for completion confirmation.'],
                    ]);
                }

                $lockedBooking->forceFill([
                    'status' => CleaningBookingStatus::Completed,
                    'customer_confirmed_at' => now(),
                ])->save();

                return $this->freshBooking($lockedBooking);
            }

            $decisionWorkerId = (int) $assignment->worker_id;
            $decisionAssignmentId = (int) $assignment->id;
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::Completed,
            ])->save();

            $status = $this->resolveBookingStatus($lockedBooking);
            $updates = [
                'status' => $status,
                'customer_confirmed_at' => now(),
            ];

            if ($status === CleaningBookingStatus::Completed) {
                $updates['work_finished_at'] = $this->latestAssignmentFinishAt($lockedBooking) ?? now();
            } else {
                $updates['work_finished_at'] = null;
            }

            $lockedBooking->forceFill($updates)->save();

            return $this->freshBooking($lockedBooking);
        });

        if ($updated->status === CleaningBookingStatus::Completed) {
            $this->recordAdminFees($updated);
        }

        $this->dispatchTrackingUpdate($updated, $decisionWorkerId, $decisionAssignmentId);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $decisionWorkerId ?? $updated->worker_id,
            'approved',
            null,
            now()->toIso8601String(),
            $updated->status?->value,
            null,
        ));
        $this->notifyWorkerDecision(
            booking: $updated,
            workerId: $decisionWorkerId,
            assignmentId: $decisionAssignmentId,
            canonicalType: 'cleaning.booking.completion_approved',
            action: 'completion_approved',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->customer_confirmed_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    public function reject(CleaningBooking $booking, ?string $message = null, ?int $workerId = null, ?int $assignmentId = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;
        $decisionWorkerId = null;
        $decisionAssignmentId = null;

        $updated = DB::transaction(function () use ($booking, $message, $workerId, $assignmentId, &$decisionWorkerId, &$decisionAssignmentId): CleaningBooking {
            $lockedBooking = $this->lockBooking($booking);
            $assignment = $this->resolvePendingCompletionAssignment($lockedBooking, $workerId, $assignmentId, true);

            if (! $assignment instanceof CleaningBookingWorkerAssignment) {
                if ($lockedBooking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
                    throw ValidationException::withMessages([
                        'status' => ['Order is not waiting for completion confirmation.'],
                    ]);
                }

                $lockedBooking->forceFill([
                    'status' => CleaningBookingStatus::InProgress,
                    'work_finished_at' => null,
                    'customer_completion_rejection_message' => $message,
                    'completion_rejected_at' => now(),
                ])->save();

                return $this->freshBooking($lockedBooking);
            }

            $decisionWorkerId = (int) $assignment->worker_id;
            $decisionAssignmentId = (int) $assignment->id;
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                'work_finished_at' => null,
                'worker_completion_message' => null,
                'worker_finished_cleaning_services' => null,
                'worker_finished_property_rooms' => null,
            ])->save();

            $lockedBooking->forceFill([
                'status' => $this->resolveBookingStatus($lockedBooking),
                'work_finished_at' => null,
                'customer_completion_rejection_message' => $message,
                'completion_rejected_at' => now(),
            ])->save();

            return $this->freshBooking($lockedBooking);
        });

        $this->dispatchTrackingUpdate($updated, $decisionWorkerId, $decisionAssignmentId);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $decisionWorkerId ?? $updated->worker_id,
            'rejected',
            $message,
            now()->toIso8601String(),
            $updated->status?->value,
            null,
        ));
        $this->notifyWorkerDecision(
            booking: $updated,
            workerId: $decisionWorkerId,
            assignmentId: $decisionAssignmentId,
            canonicalType: 'cleaning.booking.completion_rejected',
            action: 'completion_rejected',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    /**
     * @return array{booking:CleaningBooking,extensionPricing:array<string,mixed>,warning:CleaningTimeWarning}
     */
    public function requestExtension(CleaningBooking $booking, int $additionalMinutes, ?string $customerMessage = null, ?int $workerId = null, ?int $assignmentId = null): array
    {
        $fromStatus = (string) $booking->status->value;
        $extensionPricing = $this->extendedTimePricing->quote($additionalMinutes);
        $decisionWorkerId = null;
        $decisionAssignmentId = null;

        $result = DB::transaction(function () use ($booking, $additionalMinutes, $customerMessage, $extensionPricing, $workerId, $assignmentId, &$decisionWorkerId, &$decisionAssignmentId): array {
            $lockedBooking = $this->lockBooking($booking);
            $assignment = $this->resolvePendingCompletionAssignment($lockedBooking, $workerId, $assignmentId, true);

            if (! $assignment instanceof CleaningBookingWorkerAssignment) {
                if ($lockedBooking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
                    throw ValidationException::withMessages([
                        'status' => ['Order is not waiting for completion confirmation.'],
                    ]);
                }
            }

            $decisionWorkerId = $assignment instanceof CleaningBookingWorkerAssignment ? (int) $assignment->worker_id : $lockedBooking->worker_id;
            $decisionAssignmentId = $assignment instanceof CleaningBookingWorkerAssignment ? (int) $assignment->id : null;

            $warning = CleaningTimeWarning::query()->create([
                'booking_id' => $lockedBooking->id,
                'booking_type' => $lockedBooking->getMorphClass(),
                'worker_id' => $decisionWorkerId,
                'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
                'customer_message' => $customerMessage,
                'worker_response' => null,
                'sent_at' => now(),
                'customer_responded_at' => now(),
                'worker_responded_at' => null,
                'additional_minutes' => $additionalMinutes,
                'quoted_amount' => $extensionPricing['calculatedExtensionPrice'],
                'quoted_currency' => $extensionPricing['currency'],
                'price_applied_at' => null,
                'worker_reject_message' => null,
            ]);

            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
                ])->save();
            }

            $lockedBooking->forceFill([
                'status' => CleaningBookingStatus::TimeExtensionRequested,
            ])->save();

            return [
                'booking' => $this->freshBooking($lockedBooking),
                'warning' => $warning->fresh(['booking']),
            ];
        });

        $updated = $result['booking'];
        $warning = $result['warning'];

        $this->dispatchTrackingUpdate($updated, $decisionWorkerId, $decisionAssignmentId);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $decisionWorkerId ?? $updated->worker_id,
            'extension_requested',
            $customerMessage,
            now()->toIso8601String(),
            $updated->status?->value,
            $warning->id,
        ));
        $this->notifyWorkerDecision(
            booking: $updated,
            workerId: $decisionWorkerId,
            assignmentId: $decisionAssignmentId,
            canonicalType: 'cleaning.booking.time_extension_requested',
            action: 'time_extension_requested',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->updated_at?->toIso8601String(),
            extraData: ['warningId' => $warning->id],
            templateContext: ['warningId' => $warning->id],
        );

        return [
            'booking' => $updated,
            'extensionPricing' => $extensionPricing,
            'warning' => $warning,
        ];
    }

    public function resolveBookingStatus(CleaningBooking $booking): CleaningBookingStatus
    {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->lockForUpdate()
            ->get();

        $requiredWorkers = max(1, (int) ($booking->number_of_workers ?? 1));

        if ($assignments->isEmpty()) {
            return $booking->status instanceof CleaningBookingStatus
                ? $booking->status
                : CleaningBookingStatus::tryFrom((string) $booking->status) ?? CleaningBookingStatus::Pending;
        }

        $awaitingCustomer = $assignments->contains(fn (CleaningBookingWorkerAssignment $assignment): bool => $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value);
        if ($awaitingCustomer) {
            return CleaningBookingStatus::AwaitingCustomerCompletion;
        }

        $hasExtension = $assignments->contains(fn (CleaningBookingWorkerAssignment $assignment): bool => $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value);
        if ($hasExtension) {
            return CleaningBookingStatus::TimeExtensionRequested;
        }

        $completed = $assignments->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::Completed->value)->count();
        if ($completed >= $requiredWorkers) {
            return CleaningBookingStatus::Completed;
        }

        $inProgress = $assignments->contains(fn (CleaningBookingWorkerAssignment $assignment): bool => $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::InProgress->value && $assignment->work_started_at !== null);
        if ($inProgress) {
            return CleaningBookingStatus::InProgress;
        }

        $startApproved = $assignments->contains(fn (CleaningBookingWorkerAssignment $assignment): bool => $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::StartApproved->value || $assignment->start_approved_at !== null);
        if ($startApproved) {
            return CleaningBookingStatus::AwaitingWorkerStartConfirmation;
        }

        $awaitingStartVerification = $assignments->contains(fn (CleaningBookingWorkerAssignment $assignment): bool => $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value);
        if ($awaitingStartVerification) {
            return CleaningBookingStatus::AwaitingStartVerification;
        }

        return CleaningBookingStatus::WorkerAssigned;
    }

    private function resolvePendingCompletionAssignment(CleaningBooking $booking, ?int $workerId = null, ?int $assignmentId = null, bool $lock = false): ?CleaningBookingWorkerAssignment
    {
        $query = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value);

        if ($assignmentId !== null) {
            $query->whereKey($assignmentId);
        }

        if ($workerId !== null) {
            $query->where('worker_id', $workerId);
        }

        $query->orderBy('work_finished_at')->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $assignment = $query->first();

        if (! $assignment instanceof CleaningBookingWorkerAssignment && ($workerId !== null || $assignmentId !== null)) {
            throw ValidationException::withMessages([
                'status' => ['Selected worker completion request is not waiting for customer confirmation.'],
            ]);
        }

        return $assignment;
    }

    private function latestAssignmentFinishAt(CleaningBooking $booking): mixed
    {
        return CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereNotNull('work_finished_at')
            ->orderByDesc('work_finished_at')
            ->value('work_finished_at');
    }

    private function recordAdminFees(CleaningBooking $booking): void
    {
        $booking->loadMissing(['workerAssignments.worker']);

        foreach ($booking->workerAssignments as $assignment) {
            $status = $this->assignmentStatus($assignment);

            if (! in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                continue;
            }

            $worker = $assignment->worker;
            $adminFee = (float) $assignment->admin_margin_amount;

            if ($worker instanceof Worker && $adminFee > 0) {
                $this->depositService->recordAdminFeeDebit($worker, $booking, $adminFee);
            }
        }
    }

    private function notifyWorkerDecision(
        CleaningBooking $booking,
        ?int $workerId,
        ?int $assignmentId,
        string $canonicalType,
        string $action,
        string $actorRole,
        ?string $fromStatus = null,
        ?string $occurredAt = null,
        array $extraData = [],
        array $templateContext = [],
    ): void {
        if ($workerId !== null) {
            $this->lifecycleNotifications->notifyWorkerById(
                booking: $booking,
                workerId: $workerId,
                canonicalType: $canonicalType,
                action: $action,
                actorRole: $actorRole,
                fromStatus: $fromStatus,
                occurredAt: $occurredAt,
                extraData: array_merge(['assignmentId' => $assignmentId], $extraData),
                templateContext: array_merge(['assignmentId' => $assignmentId], $templateContext),
            );

            return;
        }

        $this->lifecycleNotifications->notifyWorker(
            booking: $booking,
            canonicalType: $canonicalType,
            action: $action,
            actorRole: $actorRole,
            fromStatus: $fromStatus,
            occurredAt: $occurredAt,
            extraData: $extraData,
            templateContext: $templateContext,
        );
    }

    private function lockBooking(CleaningBooking $booking): CleaningBooking
    {
        return CleaningBooking::query()->lockForUpdate()->findOrFail($booking->id);
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

    private function assignmentStatus(CleaningBookingWorkerAssignment $assignment): string
    {
        return $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking, ?int $workerId = null, ?int $assignmentId = null): void
    {
        $status = $booking->status instanceof CleaningBookingStatus ? $booking->status->value : (string) $booking->status;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $status,
            'statusLabel' => $booking->status instanceof CleaningBookingStatus ? $booking->status->label() : null,
            'workerId' => $workerId ?? $booking->worker_id,
            'assignmentId' => $assignmentId,
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
}

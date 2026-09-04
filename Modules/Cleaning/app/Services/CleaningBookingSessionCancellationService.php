<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionFinancialPenalty;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionCancellationService
{
    private const WORKER_PENALTY_REFERENCE_PREFIX = 'cleaning_session_cancellation_penalty:';

    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerTrustService $workerTrustService,
        private readonly CleaningLifecycleNotificationService $notifications,
        private readonly CleaningBookingSessionParentStateService $parentState,
    ) {}

    public function cancelByCustomer(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $reason,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        $normalizedReason = $this->requiredReason($reason);
        $affectedWorkerIds = [];
        $fromStatus = '';

        $updated = DB::transaction(function () use (
            $booking,
            $session,
            $customerId,
            $normalizedReason,
            &$affectedWorkerIds,
            &$fromStatus,
        ): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertCustomerCanCancel($locked);
            $fromStatus = $this->statusValue($locked);
            $cancelledAt = now();
            $fee = max(0.0, CleaningFinancialSetting::currentCancellationFee());

            $assignments = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->lockForUpdate()
                ->get();

            $affectedWorkerIds = $assignments
                ->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
                ->filter(static fn (int $workerId): bool => $workerId > 0)
                ->unique()
                ->values()
                ->all();

            foreach ($assignments as $assignment) {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                    'released_at' => $cancelledAt,
                    'released_reason' => 'Customer cancelled session: '.$normalizedReason,
                ])->save();
            }

            $locked->forceFill([
                'status' => CleaningBookingSessionStatus::Cancelled,
                'cancellation_fee' => $fee,
                'cancelled_at' => $cancelledAt,
                'cancellation_reason' => $normalizedReason,
                'cancelled_by_role' => CleaningBookingSessionFinancialPenalty::ROLE_CUSTOMER,
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();

            $this->recordCustomerFinancialPenalty(
                $booking,
                $locked,
                $customerId,
                $normalizedReason,
                $fee,
            );
            $this->syncParentFinancials($booking);

            return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
        });

        $this->parentState->refresh($booking);

        foreach ($affectedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $booking->fresh() ?? $booking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.order_cancelled',
                action: 'customer_cancelled_session',
                actorRole: 'customer',
                fromStatus: $fromStatus !== '' ? $fromStatus : null,
                occurredAt: $updated->cancelled_at?->toIso8601String(),
                extraData: $this->sessionContext($updated),
            );
        }

        return $updated->fresh(['workerAssignments.worker.user']) ?? $updated;
    }

    public function skipRecurringByCustomer(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $reason,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        $normalizedReason = $this->requiredReason($reason);
        $affectedWorkerIds = [];
        $fromStatus = '';

        $updated = DB::transaction(function () use (
            $booking,
            $session,
            $normalizedReason,
            &$affectedWorkerIds,
            &$fromStatus,
        ): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertCustomerCanSkipRecurring($locked);
            $fromStatus = $this->statusValue($locked);
            $skippedAt = now();

            $assignments = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->get();

            if ($assignments->contains(
                static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->started_travel_at !== null,
            )) {
                throw new InvalidArgumentException('A recurring session cannot be skipped after worker travel starts.');
            }

            $affectedWorkerIds = $assignments
                ->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
                ->filter(static fn (int $workerId): bool => $workerId > 0)
                ->unique()
                ->values()
                ->all();

            foreach ($assignments as $assignment) {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                    'released_at' => $skippedAt,
                    'released_reason' => 'Customer skipped recurring session: '.$normalizedReason,
                ])->save();
            }

            $locked->forceFill([
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Skipped,
                'cancellation_fee' => 0,
                'skipped_at' => $skippedAt,
                'skip_source' => 'customer',
                'skip_reason' => $normalizedReason,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'cancelled_by_role' => null,
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();

            $this->syncParentFinancials($booking);

            return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
        });

        $this->parentState->refresh($booking);

        foreach ($affectedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $booking->fresh() ?? $booking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: 'customer_skipped_recurring_session',
                actorRole: 'customer',
                fromStatus: $fromStatus !== '' ? $fromStatus : null,
                occurredAt: $updated->skipped_at?->toIso8601String(),
                extraData: array_merge($this->sessionContext($updated), [
                    'skipReason' => $normalizedReason,
                    'skip_reason' => $normalizedReason,
                ]),
            );
        }

        return $updated->fresh(['workerAssignments.worker.user']) ?? $updated;
    }

    public function cancelByWorker(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
        string $reason,
    ): CleaningBookingSession {
        $normalizedReason = $this->requiredReason($reason);
        $fromStatus = '';
        $fee = max(0.0, CleaningFinancialSetting::currentCancellationFee());

        $updated = DB::transaction(function () use (
            $booking,
            $session,
            $worker,
            $normalizedReason,
            $fee,
            &$fromStatus,
        ): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertWorkerCanWithdraw($locked);
            $fromStatus = $this->statusValue($locked);

            $assignment = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->where('worker_id', $worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof CleaningBookingSessionWorkerAssignment) {
                throw new InvalidArgumentException('Worker is not actively assigned to this session.');
            }
            if ($assignment->started_travel_at !== null) {
                throw new InvalidArgumentException('Worker cannot cancel a session after starting travel.');
            }

            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                'released_at' => now(),
                'released_reason' => $normalizedReason,
            ])->save();

            $acceptedCount = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->count();
            $requiredCount = $locked->requiredWorkerCount();
            $coverage = match (true) {
                $acceptedCount <= 0 => CleaningBookingSessionCoverageStatus::Searching,
                $acceptedCount < $requiredCount => CleaningBookingSessionCoverageStatus::PartiallyCovered,
                default => CleaningBookingSessionCoverageStatus::FullyCovered,
            };

            $locked->forceFill([
                'coverage_status' => $coverage,
                'status' => $acceptedCount > 0
                    ? CleaningBookingSessionStatus::WorkerAssigned
                    : CleaningBookingSessionStatus::Scheduled,
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();

            $this->recordWorkerFinancialPenalty($booking, $locked, $worker, $normalizedReason, $fee);

            return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
        });

        $this->workerTrustService->applyRejectAfterAcceptPenalty($worker, $booking);
        $this->parentState->refresh($booking);
        $this->notifications->notifyCustomer(
            booking: $booking->fresh(['customer']) ?? $booking,
            canonicalType: 'cleaning.booking.worker_rejected',
            action: 'worker_cancelled_session',
            actorRole: 'worker',
            fromStatus: $fromStatus !== '' ? $fromStatus : null,
            occurredAt: now()->toIso8601String(),
            extraData: array_merge($this->sessionContext($updated), [
                'coverageStatus' => $updated->coverage_status?->value ?? (string) $updated->coverage_status,
            ]),
        );

        return $updated->fresh(['workerAssignments.worker.user']) ?? $updated;
    }

    private function recordCustomerFinancialPenalty(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $reason,
        float $fee,
    ): void {
        if ($fee <= 0) {
            return;
        }

        CleaningBookingSessionFinancialPenalty::query()->firstOrCreate(
            ['reference_key' => 'customer:'.$session->id],
            [
                'cleaning_booking_id' => $booking->id,
                'cleaning_booking_session_id' => $session->id,
                'customer_id' => $customerId,
                'penalized_role' => CleaningBookingSessionFinancialPenalty::ROLE_CUSTOMER,
                'financial_source' => CleaningBookingSessionFinancialPenalty::SOURCE_CUSTOMER_FEE,
                'amount' => $fee,
                'status' => CleaningBookingSessionFinancialPenalty::STATUS_ACTIVE,
                'reason_snapshot' => $reason,
                'applied_at' => now(),
            ],
        );
    }

    private function recordWorkerFinancialPenalty(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
        string $reason,
        float $fee,
    ): void {
        if ($fee <= 0) {
            return;
        }

        $reference = self::WORKER_PENALTY_REFERENCE_PREFIX.$session->id.':'.$worker->id;
        $existing = CleaningBookingSessionFinancialPenalty::query()
            ->where('reference_key', $reference)
            ->first();
        if ($existing instanceof CleaningBookingSessionFinancialPenalty) {
            return;
        }

        $transaction = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('reference', $reference)
            ->first();
        if (! $transaction instanceof CleaningDepositTransaction) {
            $transaction = $this->depositService->recordDebtCharge(
                worker: $worker,
                amount: $fee,
                reference: $reference,
                notes: 'غرامة إلغاء العامل للجلسة '.$session->sequence.' من الطلب '.$booking->booking_number.' — '.$reason,
            );
        }

        CleaningBookingSessionFinancialPenalty::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'worker_id' => $worker->id,
            'financial_transaction_id' => $transaction->id,
            'reference_key' => $reference,
            'penalized_role' => CleaningBookingSessionFinancialPenalty::ROLE_WORKER,
            'financial_source' => CleaningBookingSessionFinancialPenalty::SOURCE_DEBT,
            'amount' => $fee,
            'status' => CleaningBookingSessionFinancialPenalty::STATUS_ACTIVE,
            'reason_snapshot' => $reason,
            'applied_at' => now(),
        ]);
    }

    private function syncParentFinancials(CleaningBooking $booking): void
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->get();

        $chargeable = $sessions->reject(function (CleaningBookingSession $session): bool {
            return in_array($this->statusValue($session), [
                CleaningBookingSessionStatus::Cancelled->value,
                CleaningBookingSessionStatus::Skipped->value,
            ], true);
        });
        $cancelled = $sessions->filter(
            fn (CleaningBookingSession $session): bool => $this->statusValue($session) === CleaningBookingSessionStatus::Cancelled->value,
        );

        $basePrice = round((float) $chargeable->sum('base_price'), 2);
        $addonsTotal = round((float) $chargeable->sum('addons_total'), 2);
        $travelFee = round((float) $chargeable->sum('travel_fee'), 2);
        $adminMargin = round((float) $chargeable->sum('admin_margin_amount'), 2);
        $extensionFee = round((float) $chargeable->sum('extension_fee_total'), 2);
        $cancellationFee = round((float) $cancelled->sum('cancellation_fee'), 2);
        $serviceTotal = round((float) $chargeable->sum('total_price'), 2);
        $totalHours = round((float) $chargeable->sum('duration_hours'), 2);

        CleaningBooking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail()
            ->forceFill([
                'base_price' => $basePrice,
                'addons_total' => $addonsTotal,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                'extension_fee_total' => $extensionFee,
                'cancellation_fee' => $cancellationFee,
                'total_hours' => $totalHours,
                'total_price' => round($serviceTotal + $cancellationFee, 2),
            ])->saveQuietly();
    }

    private function lockSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
    ): CleaningBookingSession {
        $locked = CleaningBookingSession::query()
            ->whereKey($session->id)
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof CleaningBookingSession) {
            throw new InvalidArgumentException('Session does not belong to this booking.');
        }

        return $locked;
    }

    private function assertCustomerCanCancel(CleaningBookingSession $session): void
    {
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Session is already closed.');
        }
        if ($session->work_started_at !== null) {
            throw new InvalidArgumentException('A session that already started work cannot be cancelled.');
        }
    }

    private function assertCustomerCanSkipRecurring(CleaningBookingSession $session): void
    {
        if ((string) $session->session_type !== 'recurring_cleaning') {
            throw new InvalidArgumentException('Only recurring cleaning sessions can be skipped.');
        }
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Session is already closed.');
        }
        if ($session->started_travel_at !== null || $session->work_started_at !== null) {
            throw new InvalidArgumentException('A recurring session cannot be skipped after worker travel starts.');
        }
    }

    private function assertWorkerCanWithdraw(CleaningBookingSession $session): void
    {
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Session is already closed.');
        }
        if ($session->work_started_at !== null) {
            throw new InvalidArgumentException('Worker can only cancel this session before work starts.');
        }
    }

    private function requiredReason(string $reason): string
    {
        $normalized = mb_trim($reason);
        if ($normalized === '') {
            throw new InvalidArgumentException('Cancellation reason is required.');
        }

        return mb_substr($normalized, 0, 1000);
    }

    private function statusValue(CleaningBookingSession $session): string
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;
    }

    /** @return array<string, int|string|null> */
    private function sessionContext(CleaningBookingSession $session): array
    {
        return [
            'sessionId' => (int) $session->id,
            'session_id' => (int) $session->id,
            'sessionSequence' => (int) $session->sequence,
            'session_sequence' => (int) $session->sequence,
            'scheduledDate' => $session->scheduled_date?->format('Y-m-d'),
            'scheduled_date' => $session->scheduled_date?->format('Y-m-d'),
        ];
    }
}

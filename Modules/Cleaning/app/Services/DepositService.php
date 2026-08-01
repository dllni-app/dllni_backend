<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use RuntimeException;

final class DepositService
{
    public function recordDeposit(Worker $worker, float $amount, string $reference, ?string $notes = null, ?int $createdByAdminId = null): CleaningDepositTransaction
    {
        $this->assertPositive($amount, 'Deposit');

        return DB::transaction(function () use ($worker, $amount, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            $account = $this->accountForUpdate($worker);
            $this->normalizeAccount($account);

            $account->is_active = true;
            $account->deposited_total = (float) $account->deposited_total + $amount;
            $settled = min($amount, (float) $account->debt_balance);
            $last = null;

            if ($settled > 0) {
                $last = $this->applySettlement($worker, $account, $settled, $reference.':debt-settlement', $notes, $createdByAdminId);
            }

            $remainder = $amount - $settled;
            if ($remainder > 0) {
                $depositBefore = (float) $account->current_balance;
                $debt = (float) $account->debt_balance;
                $account->current_balance = $depositBefore + $remainder;
                $account->save();

                $last = $this->transaction($worker, 'deposit', $remainder, $settled > 0 ? $reference.':deposit-remainder' : $reference, $notes, $createdByAdminId, $depositBefore, (float) $account->current_balance, $debt, $debt);
            }

            if (! $last instanceof CleaningDepositTransaction) {
                throw new RuntimeException('Unable to record deposit transaction.');
            }

            $this->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $last;
        });
    }

    /** @deprecated Use recordRefund(). */
    public function recordWithdrawal(Worker $worker, float $amount, string $reference, ?string $notes = null, ?int $createdByAdminId = null): CleaningDepositTransaction
    {
        return $this->recordRefund($worker, $amount, $reference, $notes, $createdByAdminId);
    }

    public function recordSettlement(Worker $worker, float $amount, string $reference, ?string $notes = null, ?int $createdByAdminId = null): CleaningDepositTransaction
    {
        $this->assertPositive($amount, 'Settlement');

        return DB::transaction(function () use ($worker, $amount, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            $account = $this->accountForUpdate($worker);
            $this->normalizeAccount($account);

            if ((float) $account->debt_balance <= 0) {
                throw new InvalidArgumentException('The worker has no outstanding debt.');
            }
            if ($amount > (float) $account->debt_balance) {
                throw new InvalidArgumentException('Settlement amount cannot exceed the outstanding debt.');
            }

            $transaction = $this->applySettlement($worker, $account, $amount, $reference, $notes, $createdByAdminId);
            $this->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $transaction;
        });
    }

    public function recordRefund(Worker $worker, float $amount, string $reference, ?string $notes = null, ?int $createdByAdminId = null): CleaningDepositTransaction
    {
        $this->assertPositive($amount, 'Refund');

        return DB::transaction(function () use ($worker, $amount, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            $account = $this->accountForUpdate($worker);
            $this->normalizeAccount($account);

            if ((float) $account->debt_balance > 0) {
                throw new InvalidArgumentException('Outstanding debt must be settled before refunding the deposit.');
            }
            if ($amount > (float) $account->current_balance) {
                throw new InvalidArgumentException('Refund amount cannot exceed the current deposit balance.');
            }

            $before = (float) $account->current_balance;
            $account->current_balance = $before - $amount;
            $account->withdrawn_total = (float) $account->withdrawn_total + $amount;
            $account->save();

            $transaction = $this->transaction($worker, 'refund', $amount, $reference, $notes, $createdByAdminId, $before, (float) $account->current_balance, 0, 0);
            $this->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $transaction;
        });
    }

    /** @deprecated Use a deposit or refund transaction instead. */
    public function recordAdjustment(Worker $worker, float $signedAmount, string $reference, ?string $notes = null, ?int $createdByAdminId = null): CleaningDepositTransaction
    {
        if ($signedAmount === 0.0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero.');
        }

        return $signedAmount > 0
            ? $this->recordDeposit($worker, $signedAmount, $reference, $notes, $createdByAdminId)
            : $this->recordRefund($worker, abs($signedAmount), $reference, $notes, $createdByAdminId);
    }

    public function recordDebtCharge(Worker $worker, float $amount, string $reference, ?string $notes = null, ?int $createdByAdminId = null): CleaningDepositTransaction
    {
        return $this->recordCharge($worker, $amount, 'debt', $reference, $notes, $createdByAdminId);
    }

    public function recordAdminFeeDebit(Worker $worker, CleaningBooking $booking, float $amount, ?int $createdByAdminId = null): ?CleaningDepositTransaction
    {
        if ($amount <= 0) {
            return null;
        }

        $reference = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.hash('sha256', $worker->id.':'.$booking->id);
        if (CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('reference', $reference)->exists()) {
            return null;
        }

        return $this->recordCharge($worker, $amount, 'commission', $reference, null, $createdByAdminId);
    }

    public function resolveLimits(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $settings = $this->settings();
        $allowedDebt = (float) ($worker->deposit?->max_negative_balance ?? 0);
        $minimumRequired = max(0.0, (float) ($settings->minimum_deposit_amount ?? 0));
        $warningThreshold = max(0.0, min(100.0, (float) ($settings->allowance_warning_threshold_percent ?? 10)));

        return [
            'minimumRequired' => round($minimumRequired, 2),
            'maxNegativeBalance' => max(0.0, $allowedDebt),
            'restrictionThresholdPercent' => 100.0,
            'allowanceWarningThresholdPercent' => round($warningThreshold, 2),
        ];
    }

    /** @deprecated Negative deposit balances are no longer used. */
    public function restrictionFloor(Worker $worker): float
    {
        return 0.0;
    }

    public function financialSummary(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $account = $worker->deposit;
        $deposit = max(0.0, (float) ($account?->current_balance ?? 0));
        $debt = max(0.0, (float) ($account?->debt_balance ?? 0));
        $commission = $this->commissionFinancialSummary($worker);
        $allowance = $this->allowanceSummary($worker);
        $totals = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('refund','withdrawal') THEN ABS(amount) WHEN type='adjustment' AND amount<0 THEN ABS(amount) ELSE 0 END),0) refund_total")
            ->first();

        $revenue = (float) CleaningBookingWorkerAssignment::query()
            ->join('cleaning_bookings', 'cleaning_bookings.id', '=', 'cleaning_booking_worker_assignments.cleaning_booking_id')
            ->where('cleaning_booking_worker_assignments.worker_id', $worker->id)
            ->where('cleaning_bookings.status', CleaningBookingStatus::Completed->value)
            ->whereIn('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->sum(DB::raw('CASE WHEN COALESCE(cleaning_booking_worker_assignments.service_share_amount,0)+COALESCE(cleaning_booking_worker_assignments.travel_fee,0)-COALESCE(cleaning_booking_worker_assignments.admin_margin_amount,0) > 0 THEN COALESCE(cleaning_booking_worker_assignments.service_share_amount,0)+COALESCE(cleaning_booking_worker_assignments.travel_fee,0)-COALESCE(cleaning_booking_worker_assignments.admin_margin_amount,0) ELSE 0 END'));
        $configuredAllowance = (float) $allowance['configuredAllowedDebtLimit'];
        $usedAllowance = (float) $allowance['allowanceUsedAmount'];

        return [
            'currentDeposit' => round($deposit, 2),
            'depositedTotal' => round((float) ($account?->deposited_total ?? 0), 2),
            'completedJobs' => (int) ($worker->total_completed_jobs ?? 0),
            'totalRevenue' => round($revenue, 2),
            'totalCommission' => round((float) $commission['totalCommission'], 2),
            'commissionDue' => round($debt, 2),
            'totalSettled' => round((float) $commission['totalSettled'], 2),
            'totalRefunded' => round((float) ($totals?->refund_total ?? $account?->withdrawn_total ?? 0), 2),
            'remainingBalance' => round($deposit, 2),
            'debtBalance' => round($debt, 2),
            'restrictionThresholdPercent' => 100.0,
            'utilizationPercent' => $configuredAllowance > 0 ? round(min(100, $usedAllowance / $configuredAllowance * 100), 1) : ($usedAllowance > 0 ? 100.0 : 0.0),
            'status' => $this->resolveAccountStatus($worker),
        ];
    }

    public function resolveAccountStatus(Worker $worker): string
    {
        if (! $worker->is_active) {
            return 'inactive';
        }
        if ($worker->is_suspended) {
            return 'suspended';
        }

        if (! $this->isFinancialAccountActive($worker)) {
            return 'inactive';
        }

        return ! $this->passesDepositMinimumWhenUsingDeposit($worker)
            || $this->isAllowanceLimitExhausted($worker)
            || $this->calculateExceedance($worker) !== null
            ? 'insufficient_balance'
            : 'active';
    }

    public function calculateExceedance(Worker $worker): ?float
    {
        $allowance = $this->allowanceSummary($worker);
        $used = (float) $allowance['allowanceUsedAmount'];
        $allowed = (float) $allowance['configuredAllowedDebtLimit'];

        return $used > $allowed ? round($used - $allowed, 2) : null;
    }

    public function calculateDebtExceedance(Worker $worker): ?float
    {
        $worker->loadMissing('deposit');

        $debt = (float) ($worker->deposit?->debt_balance ?? 0);
        $allowed = max(0.0, (float) ($worker->deposit?->max_negative_balance ?? 0));

        return $debt > $allowed ? round($debt - $allowed, 2) : null;
    }

    /** @deprecated Use calculateExceedance(). */
    public function calculateWorkerRevenueExceedance(Worker $worker): ?float
    {
        return $this->calculateExceedance($worker);
    }

    public function isWorkerEligibleForDispatch(Worker $worker): bool
    {
        return $this->isWorkerEligibleForNewRequests($worker);
    }

    public function isWorkerEligibleForNewRequests(Worker $worker): bool
    {
        if (! $worker->is_active || $worker->is_suspended) {
            return false;
        }

        return $this->passesTrustFloor($worker)
            && $this->isFinancialAccountActive($worker)
            && $this->passesDepositMinimumWhenUsingDeposit($worker)
            && ! $this->isAllowanceLimitExhausted($worker)
            && $this->calculateExceedance($worker) === null;
    }

    public function isWorkerEligibleToStartWork(Worker $worker): bool
    {
        if (! $worker->is_active || $worker->is_suspended) {
            return false;
        }

        return $this->passesTrustFloor($worker)
            && $this->isFinancialAccountActive($worker)
            && $this->passesDepositMinimumWhenUsingDeposit($worker)
            && $this->calculateDebtExceedance($worker) === null;
    }

    public function canWithdraw(Worker $worker, float $amount): bool
    {
        $worker->loadMissing('deposit');

        return $amount > 0
            && $worker->deposit instanceof CleaningWorkerDeposit
            && (float) $worker->deposit->debt_balance <= 0
            && $amount <= (float) $worker->deposit->current_balance;
    }

    public function availableCommissionCapacity(Worker $worker, float $reservedCommission = 0): float
    {
        return round((float) $this->allowanceSummary($worker, $reservedCommission)['availableCommissionCapacity'], 2);
    }

    /** @return array<string, float|bool> */
    public function allowanceSummary(Worker $worker, float $reservedCommission = 0): array
    {
        $worker->loadMissing('deposit');

        $limits = $this->resolveLimits($worker);
        $configuredAllowance = round((float) $limits['maxNegativeBalance'], 2);
        $debt = round(max(0.0, (float) ($worker->deposit?->debt_balance ?? 0)), 2);
        $commission = $this->commissionFinancialSummary($worker);
        $adminCommissionBalance = round((float) $commission['adminCommissionBalance'], 2);
        $used = $debt;
        $remaining = round(max(0.0, $configuredAllowance - $used), 2);
        $reserved = round(max(0.0, $reservedCommission), 2);
        $availableAllowance = round(max(0.0, $remaining - $reserved), 2);
        $deposit = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $usesDepositBalance = $deposit > 0;
        $availableFunding = $usesDepositBalance ? $deposit : $remaining;
        $availableCommission = round(max(0.0, $availableFunding - $reserved), 2);
        $warningThreshold = round((float) ($limits['allowanceWarningThresholdPercent'] ?? 10), 2);
        $warningAmount = $configuredAllowance > 0
            ? round($configuredAllowance * $warningThreshold / 100, 2)
            : 0.0;
        $isAllowanceExhausted = ! $usesDepositBalance && $remaining <= 0;
        $isAllowanceNearLimit = ! $usesDepositBalance
            && ! $isAllowanceExhausted
            && $configuredAllowance > 0
            && $remaining <= $warningAmount;

        return [
            'configuredAllowedDebtLimit' => $configuredAllowance,
            'maxNegativeBalance' => $configuredAllowance,
            'adminCommissionBalance' => $adminCommissionBalance,
            'withdrawnAdminRevenueTotal' => round((float) $commission['withdrawnAdminRevenueTotal'], 2),
            'settledAdminRevenueTotal' => round((float) $commission['settledAdminRevenueTotal'], 2),
            'allowanceUsedAmount' => $used,
            'remainingAllowanceLimit' => $remaining,
            'remainingDebtCapacity' => $remaining,
            'availableAllowanceCapacity' => $availableAllowance,
            'availableCommissionCapacity' => $availableCommission,
            'allowanceWarningThresholdPercent' => $warningThreshold,
            'allowanceWarningThresholdAmount' => $warningAmount,
            'isUsingDepositBalance' => $usesDepositBalance,
            'isAllowanceLimitExhausted' => $isAllowanceExhausted,
            'isAllowanceNearLimit' => $isAllowanceNearLimit,
        ];
    }

    public function isAllowanceLimitExhausted(Worker $worker): bool
    {
        return (bool) $this->allowanceSummary($worker)['isAllowanceLimitExhausted'];
    }

    public function syncEligibilityStatus(Worker $worker): void
    {
        $status = $this->resolveAccountStatus($worker);

        $worker->update([
            'security_deposit_status' => $status === 'inactive' ? 'insufficient_balance' : $status,
        ]);
    }

    public function isFinancialAccountActive(Worker $worker): bool
    {
        $worker->loadMissing('deposit');
        $account = $worker->deposit;

        if ($account === null) {
            return true;
        }

        if (! (bool) ($account->is_active ?? true)) {
            return false;
        }

        if ((float) $account->current_balance > 0) {
            return true;
        }

        $latestClosingTransaction = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->whereIn('type', ['refund', 'withdrawal'])
            ->where('balance_after', '<=', 0)
            ->latest('id')
            ->first(['id', 'created_at']);

        if ($latestClosingTransaction === null) {
            $depositedTotal = max(0.0, (float) $account->deposited_total);
            $withdrawnTotal = max(0.0, (float) $account->withdrawn_total);

            return ! ($depositedTotal > 0 && $withdrawnTotal >= $depositedTotal);
        }

        $allowanceLimit = max(0.0, (float) ($account->max_negative_balance ?? 0));
        $indebtedness = max(0.0, (float) ($account->debt_balance ?? 0));
        if (
            $allowanceLimit > $indebtedness
            && $account->updated_at !== null
            && $latestClosingTransaction->created_at !== null
            && $account->updated_at->gt($latestClosingTransaction->created_at)
        ) {
            return true;
        }

        return CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('id', '>', (int) $latestClosingTransaction->id)
            ->whereIn('type', ['deposit', 'debt'])
            ->where('balance_after', '>', 0)
            ->exists();
    }

    /** @deprecated Use syncEligibilityStatus(). */
    public function updateWorkerDepositStatus(Worker $worker): void
    {
        $this->syncEligibilityStatus($worker);
    }

    public function syncAllWorkerDepositStatuses(): void
    {
        Worker::query()->with('deposit')->chunkById(100, function ($workers): void {
            foreach ($workers as $worker) {
                if ($worker instanceof Worker) {
                    $this->syncEligibilityStatus($worker);
                }
            }
        });
    }

    public function depositStatusPayload(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $account = $worker->deposit;
        $deposit = max(0.0, (float) ($account?->current_balance ?? 0));
        $debt = max(0.0, (float) ($account?->debt_balance ?? 0));
        $allowance = $this->allowanceSummary($worker);
        $configuredAllowed = (float) $allowance['configuredAllowedDebtLimit'];
        $remainingAllowed = (float) $allowance['remainingAllowanceLimit'];
        $minimumRequired = (float) $this->resolveLimits($worker)['minimumRequired'];
        $warningCode = $this->financialWarningCode($deposit, $minimumRequired, $allowance);

        return [
            'workerId' => $worker->id,
            'depositBalance' => round($deposit, 2),
            'currentBalance' => round($deposit, 2),
            'debtBalance' => round($debt, 2),
            'debtAmount' => round($debt, 2),
            'depositedTotal' => round((float) ($account?->deposited_total ?? 0), 2),
            'withdrawnTotal' => round((float) ($account?->withdrawn_total ?? 0), 2),
            'minimumRequired' => round($minimumRequired, 2),
            'allowedDebtLimit' => round($remainingAllowed, 2),
            'configuredAllowedDebtLimit' => round($configuredAllowed, 2),
            'maxNegativeBalance' => round($configuredAllowed, 2),
            'remainingDebtCapacity' => round($remainingAllowed, 2),
            'remainingAllowanceLimit' => round($remainingAllowed, 2),
            'allowanceUsedAmount' => round((float) $allowance['allowanceUsedAmount'], 2),
            'adminCommissionBalance' => round((float) $allowance['adminCommissionBalance'], 2),
            'withdrawnAdminRevenueTotal' => round((float) $allowance['withdrawnAdminRevenueTotal'], 2),
            'allowanceWarningThresholdPercent' => round((float) $allowance['allowanceWarningThresholdPercent'], 2),
            'isUsingDepositBalance' => (bool) $allowance['isUsingDepositBalance'],
            'isAllowanceLimitExhausted' => (bool) $allowance['isAllowanceLimitExhausted'],
            'isAllowanceNearLimit' => (bool) $allowance['isAllowanceNearLimit'],
            'availableCommissionCapacity' => $this->availableCommissionCapacity($worker),
            'status' => $this->resolveAccountStatus($worker),
            'exceedanceAmount' => $this->calculateExceedance($worker),
            'debtExceedanceAmount' => $this->calculateDebtExceedance($worker),
            'financialWarningCode' => $warningCode,
            'financialWarningMessage' => $this->financialWarningMessage($warningCode, $minimumRequired),
            'isEligibleForNewRequests' => $this->isWorkerEligibleForNewRequests($worker),
            'createdAt' => $account?->created_at?->toIso8601String(),
            'updatedAt' => $account?->updated_at?->toIso8601String(),
        ];
    }

    private function recordCharge(Worker $worker, float $amount, string $type, string $reference, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        $this->assertPositive($amount, 'Charge');

        return DB::transaction(function () use ($worker, $amount, $type, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            $account = $this->accountForUpdate($worker);
            $this->normalizeAccount($account);
            $depositBefore = (float) $account->current_balance;
            $debtBefore = (float) $account->debt_balance;
            $covered = min($depositBefore, $amount);

            $account->current_balance = $depositBefore - $covered;
            $account->debt_balance = $debtBefore + ($amount - $covered);
            $account->save();

            $transaction = $this->transaction($worker, $type, $amount, $reference, $notes, $createdByAdminId, $depositBefore, (float) $account->current_balance, $debtBefore, (float) $account->debt_balance);
            $this->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $transaction;
        });
    }

    private function applySettlement(Worker $worker, CleaningWorkerDeposit $account, float $amount, string $reference, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        $deposit = (float) $account->current_balance;
        $debtBefore = (float) $account->debt_balance;
        $account->debt_balance = $debtBefore - $amount;
        $account->save();

        return $this->transaction($worker, 'settlement', $amount, $reference, $notes, $createdByAdminId, $deposit, $deposit, $debtBefore, (float) $account->debt_balance, $amount);
    }

    private function transaction(Worker $worker, string $type, float $amount, string $reference, ?string $notes, ?int $createdByAdminId, float $depositBefore, float $depositAfter, float $debtBefore, float $debtAfter, float $debtSettledAmount = 0): CleaningDepositTransaction
    {
        return CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            'created_by_admin_id' => $createdByAdminId,
            'type' => $type,
            'amount' => $amount,
            'debt_settled_amount' => $debtSettledAmount,
            'balance_before' => $depositBefore,
            'balance_after' => $depositAfter,
            'debt_balance_before' => $debtBefore,
            'debt_balance_after' => $debtAfter,
            'reference' => $reference,
            'notes' => $notes,
        ]);
    }

    /** @return array<string, float> */
    private function commissionFinancialSummary(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $prefix = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';

        $totals = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('commission','admin_fee') OR (type='debt' AND reference LIKE ?) THEN amount ELSE 0 END),0) commission_total", [$prefix])
            ->selectRaw("COALESCE(SUM(CASE WHEN type='settlement' THEN amount ELSE 0 END),0) settlement_total")
            ->first();

        $totalCommission = max(0.0, (float) ($totals?->commission_total ?? 0));
        $settledTotal = max(0.0, (float) ($totals?->settlement_total ?? 0));
        $withdrawnAdminRevenue = max(0.0, (float) ($worker->deposit?->admin_revenue_withdrawn_total ?? 0));
        $settledAdminRevenue = min($totalCommission, $settledTotal);
        $closedAdminRevenue = min($totalCommission, $settledAdminRevenue + $withdrawnAdminRevenue);

        return [
            'totalCommission' => round($totalCommission, 2),
            'totalSettled' => round($settledTotal, 2),
            'settledAdminRevenueTotal' => round($settledAdminRevenue, 2),
            'withdrawnAdminRevenueTotal' => round($withdrawnAdminRevenue, 2),
            'adminCommissionBalance' => round(max(0.0, $totalCommission - $closedAdminRevenue), 2),
        ];
    }

    private function accountForUpdate(Worker $worker): CleaningWorkerDeposit
    {
        $account = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
        if ($account instanceof CleaningWorkerDeposit) {
            return $account;
        }

        $created = CleaningWorkerDeposit::query()->create([
            'worker_id' => $worker->id,
            'current_balance' => 0,
            'debt_balance' => 0,
            'deposited_total' => 0,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 0,
            'is_active' => true,
        ]);

        return CleaningWorkerDeposit::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
    }

    private function normalizeAccount(CleaningWorkerDeposit $account): void
    {
        $deposit = max(0.0, (float) $account->current_balance);
        $debt = max(0.0, (float) $account->debt_balance);
        $offset = min($deposit, $debt);
        $normalizedDeposit = $deposit - $offset;
        $normalizedDebt = $debt - $offset;

        if ($normalizedDeposit !== (float) $account->current_balance || $normalizedDebt !== (float) $account->debt_balance) {
            $account->forceFill(['current_balance' => $normalizedDeposit, 'debt_balance' => $normalizedDebt])->save();
        }
    }

    private function assertPositive(float $amount, string $name): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("{$name} amount must be greater than zero.");
        }
    }

    private function passesTrustFloor(Worker $worker): bool
    {
        return (int) $worker->trust_score >= (int) $this->settings()->trust_minimum_for_dispatch;
    }

    private function passesDepositMinimumWhenUsingDeposit(Worker $worker): bool
    {
        $worker->loadMissing('deposit');

        $deposit = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $minimumRequired = (float) $this->resolveLimits($worker)['minimumRequired'];

        return $deposit <= 0 || $minimumRequired <= 0 || $deposit >= $minimumRequired;
    }

    /** @param array<string, mixed> $allowance */
    private function financialWarningCode(float $deposit, float $minimumRequired, array $allowance): ?string
    {
        if ($deposit > 0 && $minimumRequired > 0 && $deposit < $minimumRequired) {
            return 'deposit_below_minimum';
        }

        if ((bool) ($allowance['isAllowanceLimitExhausted'] ?? false)) {
            return 'allowance_limit_exhausted';
        }

        if ((bool) ($allowance['isAllowanceNearLimit'] ?? false)) {
            return 'allowance_near_limit';
        }

        return null;
    }

    private function financialWarningMessage(?string $warningCode, float $minimumRequired): ?string
    {
        return match ($warningCode) {
            'deposit_below_minimum' => app()->isLocale('ar')
                ? 'رصيد الإيداع أقل من الحد الأدنى المطلوب. يرجى إيداع '.number_format($minimumRequired, 2).' '.config('app.currency', 'SYP').' لتفعيل استقبال الطلبات.'
                : 'The deposit balance is below the minimum required amount. Add '.number_format($minimumRequired, 2).' '.config('app.currency', 'SYP').' to receive new requests.',
            'allowance_limit_exhausted' => app()->isLocale('ar')
                ? 'وصل حد السماح إلى الصفر. يرجى دفع المبلغ المستحق للإدارة لاستقبال طلبات جديدة.'
                : 'The allowance limit has reached zero. Pay the outstanding administration amount to receive new requests.',
            'allowance_near_limit' => app()->isLocale('ar')
                ? 'أوشك حد السماح على النفاد. يرجى دفع المبلغ المستحق للإدارة لتجنب إيقاف استقبال الطلبات.'
                : 'The allowance limit is almost exhausted. Pay the outstanding administration amount to avoid being blocked from new requests.',
            default => null,
        };
    }

    private function settings(): CleaningDepositSetting
    {
        $defaults = [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => (int) config('cleaning.trust.reject_after_accept_penalty', 10),
            'trust_minimum_for_dispatch' => 0,
        ];

        try {
            $settings = CleaningDepositSetting::query()->firstOrCreate([], $defaults);
        } catch (QueryException) {
            $settings = new CleaningDepositSetting();
        }

        foreach ($defaults as $column => $value) {
            if (! array_key_exists($column, $settings->getAttributes())) {
                $settings->setAttribute($column, $value);
            }
        }

        return $settings;
    }
}

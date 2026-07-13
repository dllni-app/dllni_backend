<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class DepositService
{
    /** @var array<string, bool> */
    private array $columnCache = [];

    public function recordDeposit(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null
    ): CleaningDepositTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Deposit amount must be greater than zero.');
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'deposit',
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
            onBalanceChange: static function (CleaningWorkerDeposit $deposit, float $amount): void {
                $deposit->deposited_total = (float) $deposit->deposited_total + $amount;
            },
        );
    }

    public function recordWithdrawal(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null
    ): CleaningDepositTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be greater than zero.');
        }

        if (! $this->canWithdraw($worker, $amount)) {
            throw new Exception('Insufficient deposit balance for withdrawal.');
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'withdrawal',
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
            onBalanceChange: static function (CleaningWorkerDeposit $deposit, float $amount): void {
                $deposit->withdrawn_total = (float) $deposit->withdrawn_total + $amount;
            },
        );
    }

    /**
     * Record a settlement payment: the worker pays down accumulated admin
     * commission. This increases the deposit balance (reducing the amount owed)
     * without changing the deposit principal.
     */
    public function recordSettlement(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null
    ): CleaningDepositTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Settlement amount must be greater than zero.');
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'settlement',
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
        );
    }

    /**
     * Record a deposit refund: money returned from the deposit balance to the
     * worker. Decreases the balance and tracks it as withdrawn principal.
     */
    public function recordRefund(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null
    ): CleaningDepositTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        if (! $this->canWithdraw($worker, $amount)) {
            throw new Exception('Insufficient deposit balance for refund.');
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'refund',
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
            onBalanceChange: static function (CleaningWorkerDeposit $deposit, float $amount): void {
                $deposit->withdrawn_total = (float) $deposit->withdrawn_total + $amount;
            },
        );
    }

    /**
     * Record a manual adjustment. A positive amount credits the balance, a
     * negative amount debits it.
     */
    public function recordAdjustment(
        Worker $worker,
        float $signedAmount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null
    ): CleaningDepositTransaction {
        if ($signedAmount === 0.0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero.');
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'adjustment',
            amount: $signedAmount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
        );
    }

    public function recordAdminFeeDebit(
        Worker $worker,
        CleaningBooking $booking,
        float $amount,
        ?int $createdByAdminId = null
    ): ?CleaningDepositTransaction {
        if ($amount <= 0 || ! $this->supportsAdminFeeTransactions()) {
            return null;
        }

        $reference = $this->adminFeeReference($worker->id, $booking->id);

        $existing = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('type', 'admin_fee')
            ->where('reference', $reference)
            ->first();

        if ($existing instanceof CleaningDepositTransaction) {
            return null;
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'admin_fee',
            amount: $amount,
            reference: $reference,
            notes: null,
            createdByAdminId: $createdByAdminId,
        );
    }

    /**
     * @return array{minimumRequired: float, maxNegativeBalance: float, restrictionThresholdPercent: float}
     */
    public function resolveLimits(Worker $worker): array
    {
        $settings = $this->settings();
        $deposit = $worker->deposit;

        return [
            'minimumRequired' => (float) ($deposit?->minimum_required ?? $settings->minimum_deposit_amount),
            'maxNegativeBalance' => (float) ($deposit?->max_negative_balance ?? $settings->default_max_negative_balance),
            'restrictionThresholdPercent' => (float) ($settings->restriction_threshold_percent ?? 80),
        ];
    }

    /**
     * The minimum balance a worker must keep before being restricted.
     *
     * Driven by the commission-utilization threshold: a worker is restricted
     * once owed commission consumes `threshold%` of their deposit principal,
     * i.e. when balance drops to `depositBase × (1 − threshold%)`. The absolute
     * max-negative-balance floor is kept as a secondary safety net.
     */
    public function restrictionFloor(Worker $worker): float
    {
        $limits = $this->resolveLimits($worker);
        $deposit = $worker->deposit;

        $depositBase = $deposit
            ? max(0.0, (float) $deposit->deposited_total - (float) $deposit->withdrawn_total)
            : 0.0;

        $utilizationFloor = $depositBase * (1 - ($limits['restrictionThresholdPercent'] / 100));
        $absoluteFloor = -$limits['maxNegativeBalance'];

        return max($utilizationFloor, $absoluteFloor);
    }

    /**
     * Complete financial overview for a worker profile / reporting.
     *
     * @return array{
     *     currentDeposit: float, depositedTotal: float, completedJobs: int,
     *     totalRevenue: float, totalCommission: float, commissionDue: float,
     *     totalSettled: float, totalRefunded: float, remainingBalance: float,
     *     restrictionThresholdPercent: float, utilizationPercent: float, status: string
     * }
     */
    public function financialSummary(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $deposit = $worker->deposit;

        $depositedTotal = (float) ($deposit?->deposited_total ?? 0);
        $withdrawnTotal = (float) ($deposit?->withdrawn_total ?? 0);
        $currentBalance = (float) ($deposit?->current_balance ?? 0);
        $depositBase = max(0.0, $depositedTotal - $withdrawnTotal);

        $sums = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'admin_fee' THEN amount ELSE 0 END), 0) as admin_fee_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlement_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('refund', 'withdrawal') THEN amount ELSE 0 END), 0) as refund_total")
            ->first();

        $commissionTotal = (float) ($sums?->admin_fee_total ?? 0);
        $settledTotal = (float) ($sums?->settlement_total ?? 0);
        $refundedTotal = (float) ($sums?->refund_total ?? 0);
        $commissionDue = max(0.0, $commissionTotal - $settledTotal);

        $revenue = (float) CleaningBookingWorkerAssignment::query()
            ->where('worker_id', $worker->id)
            ->sum(DB::raw('COALESCE(service_share_amount, 0) + COALESCE(travel_fee, 0) + COALESCE(admin_margin_amount, 0)'));

        $thresholdPercent = $this->resolveLimits($worker)['restrictionThresholdPercent'];
        $utilization = $depositBase > 0 ? round($commissionDue / $depositBase * 100, 1) : 0.0;

        return [
            'currentDeposit' => round($depositBase, 2),
            'depositedTotal' => round($depositedTotal, 2),
            'completedJobs' => (int) ($worker->total_completed_jobs ?? 0),
            'totalRevenue' => round($revenue, 2),
            'totalCommission' => round($commissionTotal, 2),
            'commissionDue' => round($commissionDue, 2),
            'totalSettled' => round($settledTotal, 2),
            'totalRefunded' => round($refundedTotal, 2),
            'remainingBalance' => round($currentBalance, 2),
            'restrictionThresholdPercent' => $thresholdPercent,
            'utilizationPercent' => $utilization,
            'status' => $this->resolveAccountStatus($worker),
        ];
    }

    /**
     * The spec-facing account status: active | restricted | inactive | suspended.
     */
    public function resolveAccountStatus(Worker $worker): string
    {
        if (! $worker->is_active) {
            return 'inactive';
        }

        if ($worker->is_suspended) {
            return 'suspended';
        }

        if ($this->isFinanceEnabled() && $this->calculateExceedance($worker) !== null) {
            return 'restricted';
        }

        return 'active';
    }

    public function calculateExceedance(Worker $worker): ?float
    {
        if (! $this->isFinanceEnabled()) {
            return null;
        }

        $deposit = $worker->deposit;
        if (! $deposit) {
            return null;
        }

        $floorBalance = $this->restrictionFloor($worker);
        $currentBalance = (float) $deposit->current_balance;

        if ($currentBalance >= $floorBalance) {
            return null;
        }

        return round($floorBalance - $currentBalance, 2);
    }

    /** @deprecated Use calculateExceedance() */
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

        if (! $this->isFinanceEnabled()) {
            return true;
        }

        if (! $this->passesTrustFloor($worker)) {
            return false;
        }

        $deposit = $worker->deposit;
        if (! $deposit) {
            return true;
        }

        return (float) $deposit->current_balance >= $this->restrictionFloor($worker);
    }

    public function isWorkerEligibleToStartWork(Worker $worker): bool
    {
        if (! $worker->is_active || $worker->is_suspended) {
            return false;
        }

        if (! $this->isFinanceEnabled()) {
            return true;
        }

        if (! $this->passesTrustFloor($worker)) {
            return false;
        }

        $deposit = $worker->deposit;
        $limits = $this->resolveLimits($worker);

        if (! $deposit) {
            return $limits['minimumRequired'] <= 0;
        }

        if ((float) $deposit->current_balance < $this->restrictionFloor($worker)) {
            return false;
        }

        return (float) $deposit->current_balance >= $limits['minimumRequired'];
    }

    public function canWithdraw(Worker $worker, float $amount): bool
    {
        $deposit = $worker->deposit;

        if (! $deposit) {
            return false;
        }

        $limits = $this->resolveLimits($worker);
        $floorBalance = -$limits['maxNegativeBalance'];
        $balanceAfter = (float) $deposit->current_balance - $amount;

        return $balanceAfter >= $floorBalance;
    }

    public function syncEligibilityStatus(Worker $worker): void
    {
        if (! $this->isFinanceEnabled()) {
            $worker->update(['security_deposit_status' => 'active']);

            return;
        }

        if ($worker->is_suspended) {
            $worker->update(['security_deposit_status' => 'suspended']);

            return;
        }

        $exceedance = $this->calculateExceedance($worker);

        $worker->update([
            'security_deposit_status' => $exceedance !== null ? 'insufficient_balance' : 'active',
        ]);
    }

    /** @deprecated Use syncEligibilityStatus() */
    public function updateWorkerDepositStatus(Worker $worker): void
    {
        $this->syncEligibilityStatus($worker);
    }

    public function syncAllWorkerDepositStatuses(): void
    {
        Worker::query()
            ->whereHas('deposit')
            ->with('deposit')
            ->chunkById(100, function ($workers): void {
                foreach ($workers as $worker) {
                    if ($worker instanceof Worker) {
                        $this->syncEligibilityStatus($worker);
                    }
                }
            });
    }

    /**
     * @return array{
     *     workerId: int,
     *     currentBalance: float,
     *     depositedTotal: float,
     *     withdrawnTotal: float,
     *     minimumRequired: float,
     *     maxNegativeBalance: float,
     *     status: string,
     *     exceedanceAmount: float|null,
     *     isEligibleForNewRequests: bool,
     *     createdAt: string|null,
     *     updatedAt: string|null
     * }
     */
    public function depositStatusPayload(Worker $worker): array
    {
        $limits = $this->resolveLimits($worker);
        $deposit = $worker->deposit;
        $isEligible = $this->isWorkerEligibleForNewRequests($worker);
        $exceedance = $this->calculateExceedance($worker);

        if (! $deposit) {
            return [
                'workerId' => $worker->id,
                'currentBalance' => 0.0,
                'depositedTotal' => 0.0,
                'withdrawnTotal' => 0.0,
                'minimumRequired' => $limits['minimumRequired'],
                'maxNegativeBalance' => $limits['maxNegativeBalance'],
                'status' => $worker->security_deposit_status ?? 'active',
                'exceedanceAmount' => $exceedance,
                'isEligibleForNewRequests' => $isEligible,
                'createdAt' => null,
                'updatedAt' => null,
            ];
        }

        return [
            'workerId' => $worker->id,
            'currentBalance' => (float) $deposit->current_balance,
            'depositedTotal' => (float) $deposit->deposited_total,
            'withdrawnTotal' => (float) $deposit->withdrawn_total,
            'minimumRequired' => $limits['minimumRequired'],
            'maxNegativeBalance' => $limits['maxNegativeBalance'],
            'status' => (string) ($worker->security_deposit_status ?? 'active'),
            'exceedanceAmount' => $exceedance,
            'isEligibleForNewRequests' => $isEligible,
            'createdAt' => $deposit->created_at?->toIso8601String(),
            'updatedAt' => $deposit->updated_at?->toIso8601String(),
        ];
    }

    private function isFinanceEnabled(): bool
    {
        return (bool) $this->settings()->is_enabled;
    }

    private function passesTrustFloor(Worker $worker): bool
    {
        $minimumTrust = (int) $this->settings()->trust_minimum_for_dispatch;

        return (int) $worker->trust_score >= $minimumTrust;
    }

    private function settings(): CleaningDepositSetting
    {
        $defaults = [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 80,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => (int) config('cleaning.trust.reject_after_accept_penalty', 10),
            'trust_minimum_for_dispatch' => 0,
        ];

        try {
            $settings = CleaningDepositSetting::query()->firstOrCreate([], $this->onlyExistingColumns('cleaning_deposit_settings', $defaults));
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

    /**
     * @param  callable(CleaningWorkerDeposit, float): void|null  $onBalanceChange
     */
    private function mutateBalance(
        Worker $worker,
        string $type,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null,
        ?callable $onBalanceChange = null,
    ): CleaningDepositTransaction {
        return DB::transaction(function () use (
            $worker,
            $type,
            $amount,
            $reference,
            $notes,
            $createdByAdminId,
            $onBalanceChange,
        ): CleaningDepositTransaction {
            $settings = $this->settings();

            $deposit = CleaningWorkerDeposit::query()
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if (! $deposit) {
                $deposit = CleaningWorkerDeposit::query()->create($this->onlyExistingColumns('cleaning_worker_deposits', [
                    'worker_id' => $worker->id,
                    'current_balance' => 0,
                    'deposited_total' => 0,
                    'withdrawn_total' => 0,
                    'minimum_required' => $settings->minimum_deposit_amount,
                    'max_negative_balance' => $settings->default_max_negative_balance,
                ]));

                $deposit = CleaningWorkerDeposit::query()
                    ->whereKey($deposit->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $balanceBefore = (float) $deposit->current_balance;
            // Credits add to the balance; an adjustment carries a signed amount.
            // Everything else (withdrawal, refund, admin_fee) debits the balance.
            $balanceAfter = in_array($type, ['deposit', 'settlement', 'adjustment'], true)
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            if ($onBalanceChange !== null) {
                $onBalanceChange($deposit, $amount);
            }

            $deposit->update(['current_balance' => $balanceAfter]);

            $transaction = CleaningDepositTransaction::query()->create($this->onlyExistingColumns('cleaning_deposit_transactions', [
                'worker_id' => $worker->id,
                'created_by_admin_id' => $createdByAdminId,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'notes' => $notes,
            ]));

            $this->syncEligibilityStatus($worker->fresh(['deposit']));

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            fn (string $column): bool => $this->hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function adminFeeReference(int $workerId, int $bookingId): string
    {
        return 'automatic_admin_commission:'.hash('sha256', $workerId.':'.$bookingId);
    }

    private function supportsAdminFeeTransactions(): bool
    {
        return $this->hasColumn('cleaning_deposit_transactions', 'created_by_admin_id');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        if (! array_key_exists($key, $this->columnCache)) {
            try {
                $this->columnCache[$key] = Schema::hasColumn($table, $column);
            } catch (QueryException) {
                $this->columnCache[$key] = false;
            }
        }

        return $this->columnCache[$key];
    }
}

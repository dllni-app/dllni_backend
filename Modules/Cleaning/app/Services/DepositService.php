<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;

final class DepositService
{
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

    public function recordAdminFeeDebit(
        Worker $worker,
        CleaningBooking $booking,
        float $amount,
        ?int $createdByAdminId = null
    ): ?CleaningDepositTransaction {
        if ($amount <= 0) {
            return null;
        }

        $existing = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('type', 'admin_fee')
            ->where('cleaning_booking_id', $booking->id)
            ->first();

        if ($existing instanceof CleaningDepositTransaction) {
            return null;
        }

        return $this->mutateBalance(
            worker: $worker,
            type: 'admin_fee',
            amount: $amount,
            reference: "admin_fee_booking_{$booking->id}",
            notes: "Admin fee for booking #{$booking->id}",
            cleaningBookingId: $booking->id,
            createdByAdminId: $createdByAdminId,
        );
    }

    /**
     * @return array{minimumRequired: float, maxNegativeBalance: float}
     */
    public function resolveLimits(Worker $worker): array
    {
        $settings = $this->settings();
        $deposit = $worker->deposit;

        return [
            'minimumRequired' => (float) ($deposit?->minimum_required ?? $settings->minimum_deposit_amount),
            'maxNegativeBalance' => (float) ($deposit?->max_negative_balance ?? $settings->default_max_negative_balance),
        ];
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

        $limits = $this->resolveLimits($worker);
        $floorBalance = -$limits['maxNegativeBalance'];
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

        $limits = $this->resolveLimits($worker);
        $floorBalance = -$limits['maxNegativeBalance'];

        return (float) $deposit->current_balance >= $floorBalance;
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

        $floorBalance = -$limits['maxNegativeBalance'];

        if ((float) $deposit->current_balance < $floorBalance) {
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
        return CleaningDepositSetting::query()->firstOrCreate([], [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => (int) config('cleaning.trust.reject_after_accept_penalty', 10),
            'trust_minimum_for_dispatch' => 0,
        ]);
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
        ?int $cleaningBookingId = null,
        ?int $createdByAdminId = null,
        ?callable $onBalanceChange = null,
    ): CleaningDepositTransaction {
        return DB::transaction(function () use (
            $worker,
            $type,
            $amount,
            $reference,
            $notes,
            $cleaningBookingId,
            $createdByAdminId,
            $onBalanceChange,
        ): CleaningDepositTransaction {
            $settings = $this->settings();

            $deposit = CleaningWorkerDeposit::query()
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if (! $deposit) {
                $deposit = CleaningWorkerDeposit::query()->create([
                    'worker_id' => $worker->id,
                    'current_balance' => 0,
                    'deposited_total' => 0,
                    'withdrawn_total' => 0,
                    'minimum_required' => $settings->minimum_deposit_amount,
                    'max_negative_balance' => $settings->default_max_negative_balance,
                ]);

                $deposit = CleaningWorkerDeposit::query()
                    ->whereKey($deposit->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $balanceBefore = (float) $deposit->current_balance;
            $balanceAfter = $type === 'deposit'
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            if ($onBalanceChange !== null) {
                $onBalanceChange($deposit, $amount);
            }

            $deposit->update(['current_balance' => $balanceAfter]);

            $transaction = CleaningDepositTransaction::query()->create([
                'worker_id' => $worker->id,
                'cleaning_booking_id' => $cleaningBookingId,
                'created_by_admin_id' => $createdByAdminId,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'notes' => $notes,
            ]);

            $this->syncEligibilityStatus($worker->fresh(['deposit']));

            return $transaction;
        });
    }
}

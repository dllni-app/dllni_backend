<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Exception;

final class DepositService
{
    public function recordDeposit(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null
    ): CleaningDepositTransaction {
        $deposit = $worker->deposit() ?? CleaningWorkerDeposit::firstOrCreate(
            ['worker_id' => $worker->id],
            ['current_balance' => 0, 'deposited_total' => 0, 'withdrawn_total' => 0]
        );

        $balanceBefore = $deposit->current_balance;
        $balanceAfter = $balanceBefore + $amount;

        $deposit->update([
            'current_balance' => $balanceAfter,
            'deposited_total' => $deposit->deposited_total + $amount,
        ]);

        $transaction = CleaningDepositTransaction::create([
            'worker_id' => $worker->id,
            'type' => 'deposit',
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        $this->updateWorkerDepositStatus($worker);

        return $transaction;
    }

    public function recordWithdrawal(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null
    ): CleaningDepositTransaction {
        $deposit = $worker->deposit();

        if (! $deposit) {
            throw new Exception('Worker does not have a deposit account.');
        }

        if ($deposit->current_balance < $amount) {
            throw new Exception('Insufficient deposit balance for withdrawal.');
        }

        $balanceBefore = $deposit->current_balance;
        $balanceAfter = $balanceBefore - $amount;

        $deposit->update([
            'current_balance' => $balanceAfter,
            'withdrawn_total' => $deposit->withdrawn_total + $amount,
        ]);

        $transaction = CleaningDepositTransaction::create([
            'worker_id' => $worker->id,
            'type' => 'withdrawal',
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        $this->updateWorkerDepositStatus($worker);

        return $transaction;
    }

    public function calculateWorkerRevenueExceedance(Worker $worker): ?float
    {
        $deposit = $worker->deposit();

        if (! $deposit) {
            return null;
        }

        $completedRevenue = $worker->cleaningBookings()
            ->where('status', 'completed')
            ->sum('total_price');

        $exceedance = $completedRevenue - $deposit->current_balance;

        return $exceedance > 0 ? $exceedance : null;
    }

    public function isWorkerEligibleForNewRequests(Worker $worker): bool
    {
        $exceedance = $this->calculateWorkerRevenueExceedance($worker);

        return $exceedance === null;
    }

    public function updateWorkerDepositStatus(Worker $worker): void
    {
        $deposit = $worker->deposit();

        if (! $deposit) {
            $worker->update(['security_deposit_status' => 'active']);

            return;
        }

        $completedRevenue = $worker->cleaningBookings()
            ->where('status', 'completed')
            ->sum('total_price');

        if ($completedRevenue > $deposit->current_balance) {
            $status = 'insufficient_balance';
        } else {
            $status = 'active';
        }

        $worker->update(['security_deposit_status' => $status]);
    }
}

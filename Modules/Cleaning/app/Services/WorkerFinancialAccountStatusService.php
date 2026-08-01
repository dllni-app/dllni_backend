<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;

final class WorkerFinancialAccountStatusService
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const SUSPENDED = 'suspended';

    private const CLOSING_TRANSACTION_TYPES = ['refund', 'withdrawal'];

    private const FUNDING_TRANSACTION_TYPES = ['deposit', 'debt'];

    public function status(Worker $worker): string
    {
        if (! $worker->is_active) {
            return self::INACTIVE;
        }

        if ($worker->is_suspended) {
            return self::SUSPENDED;
        }

        if (! $this->isFinancialAccountActive($worker)) {
            return self::INACTIVE;
        }

        $worker->loadMissing('deposit');
        $allowance = app(DepositService::class)->allowanceSummary($worker);

        return (bool) ($allowance['isAllowanceLimitExhausted'] ?? false)
            ? self::INSUFFICIENT_BALANCE
            : self::ACTIVE;
    }

    public function isActive(Worker $worker): bool
    {
        return $this->status($worker) === self::ACTIVE;
    }

    /**
     * A full financial-account refund closes the insurance/deposit account.
     * A later funding transaction reopens it, even when subsequent commissions
     * consume the available balance back to zero.
     */
    public function isFinancialAccountActive(Worker $worker): bool
    {
        $worker->loadMissing('deposit');
        $account = $worker->deposit;

        if ($account === null) {
            return true;
        }

        if ((float) $account->current_balance > 0) {
            return true;
        }

        $latestClosingTransactionId = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->whereIn('type', self::CLOSING_TRANSACTION_TYPES)
            ->where('balance_after', '<=', 0)
            ->latest('id')
            ->value('id');

        if ($latestClosingTransactionId === null) {
            $depositedTotal = max(0.0, (float) $account->deposited_total);
            $withdrawnTotal = max(0.0, (float) $account->withdrawn_total);

            // Compatibility for legacy accounts whose complete withdrawal was
            // stored only in the cumulative account totals without a transaction.
            return ! ($depositedTotal > 0 && $withdrawnTotal >= $depositedTotal);
        }

        return CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('id', '>', (int) $latestClosingTransactionId)
            ->whereIn('type', self::FUNDING_TRANSACTION_TYPES)
            ->where('balance_after', '>', 0)
            ->exists();
    }
}

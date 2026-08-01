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

    private const FULL_ACCOUNT_REFUND_REFERENCE = 'admin_full_account_refund';

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

        $latestFullRefundId = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->whereIn('type', ['refund', 'withdrawal'])
            ->where('reference', self::FULL_ACCOUNT_REFUND_REFERENCE)
            ->where('balance_after', '<=', 0)
            ->latest('id')
            ->value('id');

        if ($latestFullRefundId === null) {
            return true;
        }

        return CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('id', '>', (int) $latestFullRefundId)
            ->whereIn('type', ['deposit', 'debt'])
            ->where('balance_after', '>', 0)
            ->exists();
    }
}

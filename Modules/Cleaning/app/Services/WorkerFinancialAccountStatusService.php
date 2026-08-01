<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;

final class WorkerFinancialAccountStatusService
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const SUSPENDED = 'suspended';

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

        return match (app(DepositService::class)->resolveAccountStatus($worker)) {
            'active' => self::ACTIVE,
            'inactive' => self::INACTIVE,
            'suspended' => self::SUSPENDED,
            default => self::INSUFFICIENT_BALANCE,
        };
    }

    public function isActive(Worker $worker): bool
    {
        return $this->status($worker) === self::ACTIVE;
    }

    /**
     * A full financial-account refund closes the insurance/deposit account.
     * A later funding transaction or allowance-limit update reopens it.
     */
    public function isFinancialAccountActive(Worker $worker): bool
    {
        return app(DepositService::class)->isFinancialAccountActive($worker);
    }
}

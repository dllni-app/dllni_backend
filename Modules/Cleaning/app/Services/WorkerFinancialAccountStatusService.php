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

        $worker->loadMissing('deposit');
        $debtBalance = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));
        $allowedDebtLimit = max(0.0, (float) ($worker->deposit?->max_negative_balance ?? 0));

        return $debtBalance <= $allowedDebtLimit
            ? self::ACTIVE
            : self::INSUFFICIENT_BALANCE;
    }

    public function isActive(Worker $worker): bool
    {
        return $this->status($worker) === self::ACTIVE;
    }
}

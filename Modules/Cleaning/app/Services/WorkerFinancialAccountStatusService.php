<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositSetting;
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

        if (! $this->financeEnabled()) {
            return self::ACTIVE;
        }

        $worker->loadMissing('deposit');
        $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $debtBalance = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));
        $allowedDebtLimit = max(
            0.0,
            (float) ($worker->deposit?->max_negative_balance ?? $this->defaultAllowedDebtLimit()),
        );

        return $depositBalance > 0 && $debtBalance <= $allowedDebtLimit
            ? self::ACTIVE
            : self::INSUFFICIENT_BALANCE;
    }

    public function isActive(Worker $worker): bool
    {
        return $this->status($worker) === self::ACTIVE;
    }

    private function financeEnabled(): bool
    {
        return (bool) (CleaningDepositSetting::query()->value('is_enabled') ?? true);
    }

    private function defaultAllowedDebtLimit(): float
    {
        return (float) (CleaningDepositSetting::query()->value('default_max_negative_balance') ?? 0);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialPenalty;
use App\Models\Worker;

final class CleaningFinancialPenaltySettlementService
{
    public function clearDepositPenaltiesOnFullRefund(Worker $worker): void
    {
        $this->clear($worker, CleaningFinancialPenalty::SOURCE_DEPOSIT);
    }

    public function clearDebtPenaltiesWhenDebtIsZero(Worker $worker): void
    {
        $worker->loadMissing('deposit');

        if (max(0.0, (float) ($worker->deposit?->debt_balance ?? 0)) > 0) {
            return;
        }

        $this->clear($worker, CleaningFinancialPenalty::SOURCE_DEBT);
    }

    private function clear(Worker $worker, string $source): void
    {
        CleaningFinancialPenalty::query()
            ->where('worker_id', $worker->id)
            ->where('financial_source', $source)
            ->where('status', CleaningFinancialPenalty::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get()
            ->each(function (CleaningFinancialPenalty $penalty): void {
                $penalty->forceFill([
                    'status' => CleaningFinancialPenalty::STATUS_CLEARED,
                    'cleared_at' => now(),
                ])->save();
            });
    }
}

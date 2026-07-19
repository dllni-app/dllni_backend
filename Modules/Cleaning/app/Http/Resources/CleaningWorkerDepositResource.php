<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CleaningWorkerDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $depositBalance = max(0.0, (float) $this->current_balance);
        $debtBalance = max(0.0, (float) ($this->debt_balance ?? 0));

        return [
            'workerId' => $this->worker_id,
            'depositBalance' => $depositBalance,
            'currentBalance' => $depositBalance,
            'debtBalance' => $debtBalance,
            'debtAmount' => $debtBalance,
            'depositedTotal' => (float) $this->deposited_total,
            'withdrawnTotal' => (float) $this->withdrawn_total,
            'allowedDebtLimit' => max(0.0, (float) ($this->max_negative_balance ?? 0)),
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

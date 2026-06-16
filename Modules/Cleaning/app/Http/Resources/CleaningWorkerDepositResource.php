<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CleaningWorkerDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'workerId' => $this->worker_id,
            'currentBalance' => (float) $this->current_balance,
            'depositedTotal' => (float) $this->deposited_total,
            'withdrawnTotal' => (float) $this->withdrawn_total,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

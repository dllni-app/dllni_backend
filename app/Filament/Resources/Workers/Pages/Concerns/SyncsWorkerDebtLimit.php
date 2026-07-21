<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages\Concerns;

use App\Models\CleaningWorkerDeposit;
use Modules\Cleaning\Services\DepositService;

trait SyncsWorkerDebtLimit
{
    protected function mutateWorkerDebtLimitFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('deposit');
        $data['worker_debt_limit'] = max(0.0, (float) ($this->record->deposit?->max_negative_balance ?? 0));

        return $data;
    }

    protected function syncWorkerDebtLimitFromForm(): void
    {
        $limit = max(0.0, (float) ($this->data['worker_debt_limit'] ?? 0));

        $account = CleaningWorkerDeposit::query()->firstOrCreate(
            ['worker_id' => $this->record->id],
            [
                'current_balance' => 0,
                'debt_balance' => 0,
                'deposited_total' => 0,
                'withdrawn_total' => 0,
                'admin_revenue_withdrawn_total' => 0,
                'minimum_required' => 0,
                'max_negative_balance' => $limit,
                'is_active' => true,
            ],
        );

        $account->forceFill([
            'minimum_required' => 0,
            'max_negative_balance' => $limit,
        ])->save();

        $this->record->unsetRelation('deposit');
        app(DepositService::class)->syncEligibilityStatus($this->record->fresh(['deposit']) ?? $this->record);
    }
}

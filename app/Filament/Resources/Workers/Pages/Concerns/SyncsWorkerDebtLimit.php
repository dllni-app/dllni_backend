<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages\Concerns;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Services\AdminCleaningTransactionService;

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

        try {
            app(AdminCleaningTransactionService::class)->updateAllowanceLimit($this->record, $limit);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'data.worker_debt_limit' => $exception->getMessage(),
            ]);
        }

        $this->record->unsetRelation('deposit');
    }
}

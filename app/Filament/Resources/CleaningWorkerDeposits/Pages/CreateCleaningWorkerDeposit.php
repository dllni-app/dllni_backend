<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Pages;

use App\Filament\Resources\CleaningWorkerDeposits\CleaningWorkerDepositsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Services\AdminCleaningTransactionService;

final class CreateCleaningWorkerDeposit extends CreateRecord
{
    protected static string $resource = CleaningWorkerDepositsResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_finance_guidance.form.page_title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('cleaning_finance_guidance.form.page_subtitle');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('cleaning_finance_guidance.form.submit'));
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(AdminCleaningTransactionService::class);

        try {
            $worker = $service->findWorker((int) ($data['worker_id'] ?? 0));
            $notes = isset($data['notes']) && mb_trim((string) $data['notes']) !== ''
                ? mb_trim((string) $data['notes'])
                : null;

            return $service->create(
                worker: $worker,
                type: (string) ($data['type'] ?? ''),
                amount: (float) ($data['amount'] ?? 0),
                notes: $notes,
                createdByAdminId: auth()->id(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'data.amount' => $exception->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return CleaningWorkerDepositsResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('cleaning_admin.transactions.actions.create_success');
    }
}

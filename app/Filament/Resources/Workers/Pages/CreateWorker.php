<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerDebtLimit;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Services\AdminCleaningTransactionService;

final class CreateWorker extends CreateRecord
{
    use SyncsWorkerDebtLimit;
    use SyncsWorkerLinkedUser {
        handleRecordCreation as private createWorkerRecord;
    }

    protected static string $resource = WorkerResource::class;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation(fn (): bool => blank($this->data['initial_financial_transaction_type'] ?? null)
                && (float) ($this->data['worker_debt_limit'] ?? 0) <= 0)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('warning')
            ->modalHeading(app()->isLocale('ar') ? 'إضافة العامل بدون سعة مالية؟' : 'Create worker without financial capacity?')
            ->modalDescription(app()->isLocale('ar')
                ? 'لم يتم تسجيل إيداع وحد المديونية للعامل يساوي صفراً، لذلك لن تتوفر له سعة مالية لقبول الطلبات ذات العمولة. هل أنت متأكد؟'
                : 'No deposit was recorded and the worker debt limit is zero, so there will be no financial capacity for bookings with commission. Are you sure?')
            ->modalSubmitActionLabel(app()->isLocale('ar') ? 'إضافة العامل على أي حال' : 'Create worker anyway');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $worker = $this->createWorkerRecord($data);
            $type = $this->initialFinancialTransactionType();

            if ($type === null) {
                return $worker;
            }

            try {
                app(AdminCleaningTransactionService::class)->create(
                    worker: $worker,
                    type: $type,
                    amount: (float) ($this->data['initial_financial_transaction_amount'] ?? 0),
                    notes: $this->initialFinancialTransactionNotes(),
                    createdByAdminId: auth()->id(),
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'data.initial_financial_transaction_amount' => $exception->getMessage(),
                ]);
            }

            return $worker;
        });
    }

    protected function afterCreate(): void
    {
        $this->syncLinkedUserAccount();
        $this->syncWorkerAvatarFromForm();
        $this->syncWorkerDebtLimitFromForm();
    }

    private function initialFinancialTransactionType(): ?string
    {
        $type = $this->data['initial_financial_transaction_type'] ?? null;

        if (blank($type)) {
            return null;
        }

        if (! is_string($type) || ! in_array($type, ['deposit', 'debt'], true)) {
            throw ValidationException::withMessages([
                'data.initial_financial_transaction_type' => app()->isLocale('ar')
                    ? 'اختر إيداعاً أو ديناً إدارياً.'
                    : 'Select either a deposit or an administration loan.',
            ]);
        }

        return $type;
    }

    private function initialFinancialTransactionNotes(): ?string
    {
        $notes = mb_trim((string) ($this->data['initial_financial_transaction_notes'] ?? ''));

        return $notes === '' ? null : $notes;
    }
}

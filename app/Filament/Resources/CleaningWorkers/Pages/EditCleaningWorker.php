<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningWorker extends EditRecord
{
    use SyncsWorkerLinkedUser;

    protected static string $resource = CleaningWorkerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mutateLinkedUserFormDataBeforeFill($data);
    }

    protected function afterSave(): void
    {
        $this->syncLinkedUserAccount();
        $this->markLinkedUserAsCleaningWorker();
    }

    protected function markLinkedUserAsCleaningWorker(): void
    {
        $this->record->user?->forceFill([
            'module_type' => UserModuleType::CleaningWorker->value,
        ])->saveQuietly();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

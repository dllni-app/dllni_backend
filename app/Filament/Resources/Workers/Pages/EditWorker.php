<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditWorker extends EditRecord
{
    use SyncsWorkerLinkedUser;

    protected static string $resource = WorkerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mutateLinkedUserFormDataBeforeFill($data);
    }

    protected function afterSave(): void
    {
        $this->syncLinkedUserAccount();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

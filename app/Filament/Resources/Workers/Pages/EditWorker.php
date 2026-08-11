<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\Actions\ChangeWorkerAvatarAction;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerDebtLimit;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditWorker extends EditRecord
{
    use SyncsWorkerDebtLimit;
    use SyncsWorkerLinkedUser;

    protected static string $resource = WorkerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mutateWorkerDebtLimitFormDataBeforeFill(
            $this->mutateLinkedUserFormDataBeforeFill($data),
        );
    }

    protected function afterSave(): void
    {
        $this->syncLinkedUserAccount();
        $this->syncWorkerDebtLimitFromForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            ChangeWorkerAvatarAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningWorker extends CreateRecord
{
    use SyncsWorkerLinkedUser;

    protected static string $resource = CleaningWorkerResource::class;

    protected function afterCreate(): void
    {
        $this->syncLinkedUserAccount();
        $this->record->user?->forceFill([
            'module_type' => UserModuleType::CleaningWorker->value,
        ])->saveQuietly();
    }
}

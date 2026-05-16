<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningWorker extends CreateRecord
{
    protected static string $resource = CleaningWorkerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = UserModuleType::CleaningWorker->value;

        return $data;
    }
}

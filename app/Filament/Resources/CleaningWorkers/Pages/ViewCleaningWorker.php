<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningWorker extends ViewRecord
{
    protected static string $resource = CleaningWorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

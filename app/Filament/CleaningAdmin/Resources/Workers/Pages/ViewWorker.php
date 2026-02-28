<?php

namespace App\Filament\CleaningAdmin\Resources\Workers\Pages;

use App\Filament\CleaningAdmin\Resources\Workers\WorkerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWorker extends ViewRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

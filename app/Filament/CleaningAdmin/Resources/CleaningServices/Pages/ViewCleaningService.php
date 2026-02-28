<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningServices\CleaningServiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCleaningService extends ViewRecord
{
    protected static string $resource = CleaningServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

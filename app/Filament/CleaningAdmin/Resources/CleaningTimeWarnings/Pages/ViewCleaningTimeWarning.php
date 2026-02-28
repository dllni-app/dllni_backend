<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCleaningTimeWarning extends ViewRecord
{
    protected static string $resource = CleaningTimeWarningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

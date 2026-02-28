<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCleaningTimeWarning extends EditRecord
{
    protected static string $resource = CleaningTimeWarningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

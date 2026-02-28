<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCleaningTimeWarnings extends ListRecords
{
    protected static string $resource = CleaningTimeWarningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

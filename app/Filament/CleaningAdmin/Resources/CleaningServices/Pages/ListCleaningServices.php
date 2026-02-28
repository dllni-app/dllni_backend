<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningServices\CleaningServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCleaningServices extends ListRecords
{
    protected static string $resource = CleaningServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages;

use App\Filament\CleaningAdmin\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceAddons extends ListRecords
{
    protected static string $resource = ServiceAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

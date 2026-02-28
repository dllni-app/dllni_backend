<?php

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages;

use App\Filament\CleaningAdmin\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceAddon extends ViewRecord
{
    protected static string $resource = ServiceAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

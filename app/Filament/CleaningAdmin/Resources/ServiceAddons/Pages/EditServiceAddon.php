<?php

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages;

use App\Filament\CleaningAdmin\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceAddon extends EditRecord
{
    protected static string $resource = ServiceAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

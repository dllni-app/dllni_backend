<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Pages;

use App\Filament\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewServiceAddon extends ViewRecord
{
    protected static string $resource = ServiceAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

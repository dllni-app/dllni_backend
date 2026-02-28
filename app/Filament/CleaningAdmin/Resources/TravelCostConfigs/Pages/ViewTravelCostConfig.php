<?php

namespace App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages;

use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTravelCostConfig extends ViewRecord
{
    protected static string $resource = TravelCostConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

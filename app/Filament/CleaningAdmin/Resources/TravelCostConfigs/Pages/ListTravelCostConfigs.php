<?php

namespace App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages;

use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTravelCostConfigs extends ListRecords
{
    protected static string $resource = TravelCostConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

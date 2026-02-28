<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs\Pages;

use App\Filament\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewTravelCostConfig extends ViewRecord
{
    protected static string $resource = TravelCostConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

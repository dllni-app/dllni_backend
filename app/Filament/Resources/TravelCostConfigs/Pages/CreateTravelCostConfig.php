<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs\Pages;

use App\Filament\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateTravelCostConfig extends CreateRecord
{
    protected static string $resource = TravelCostConfigResource::class;
}

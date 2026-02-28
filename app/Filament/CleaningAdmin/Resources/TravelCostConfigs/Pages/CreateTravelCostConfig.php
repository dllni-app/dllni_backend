<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages;

use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateTravelCostConfig extends CreateRecord
{
    protected static string $resource = TravelCostConfigResource::class;
}

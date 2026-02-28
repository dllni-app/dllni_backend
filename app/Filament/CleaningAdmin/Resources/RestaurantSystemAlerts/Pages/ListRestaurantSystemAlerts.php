<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\RestaurantSystemAlertResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantSystemAlerts extends ListRecords
{
    protected static string $resource = RestaurantSystemAlertResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants\Pages;

use App\Filament\CleaningAdmin\Resources\Restaurants\RestaurantResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurants extends ListRecords
{
    protected static string $resource = RestaurantResource::class;
}

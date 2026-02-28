<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantDisputes\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantOrderDisputes extends ListRecords
{
    protected static string $resource = RestaurantOrderDisputeResource::class;
}

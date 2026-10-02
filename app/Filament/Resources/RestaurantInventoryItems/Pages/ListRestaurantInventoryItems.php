<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantInventoryItems\Pages;

use App\Filament\Resources\RestaurantInventoryItems\RestaurantInventoryItemResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantInventoryItems extends ListRecords
{
    protected static string $resource = RestaurantInventoryItemResource::class;

    public function getTitle(): string
    {
        return 'مخزون المطاعم — عرض إداري';
    }
}

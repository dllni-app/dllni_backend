<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantPromoCodes\Pages;

use App\Filament\Resources\RestaurantPromoCodes\RestaurantPromoCodeResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantPromoCodes extends ListRecords
{
    protected static string $resource = RestaurantPromoCodeResource::class;

    public function getTitle(): string
    {
        return 'كوبونات المطاعم — عرض إداري';
    }
}

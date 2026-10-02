<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantProducts\Pages;

use App\Filament\Resources\RestaurantProducts\RestaurantProductResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantProducts extends ListRecords
{
    protected static string $resource = RestaurantProductResource::class;

    public function getTitle(): string
    {
        return 'منتجات المطاعم — عرض إداري';
    }
}

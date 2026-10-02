<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOffers\Pages;

use App\Filament\Resources\RestaurantOffers\RestaurantOfferResource;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantOffers extends ListRecords
{
    protected static string $resource = RestaurantOfferResource::class;

    public function getTitle(): string
    {
        return 'عروض المطاعم — عرض إداري';
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOffers\Pages;

use App\Filament\Resources\RestaurantOffers\RestaurantOfferResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantOffer extends ViewRecord
{
    protected static string $resource = RestaurantOfferResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل العرض '.$this->record->name;
    }
}

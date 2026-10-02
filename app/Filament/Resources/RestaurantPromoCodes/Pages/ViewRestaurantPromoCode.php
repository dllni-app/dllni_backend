<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantPromoCodes\Pages;

use App\Filament\Resources\RestaurantPromoCodes\RestaurantPromoCodeResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantPromoCode extends ViewRecord
{
    protected static string $resource = RestaurantPromoCodeResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل الكوبون '.$this->record->code;
    }
}

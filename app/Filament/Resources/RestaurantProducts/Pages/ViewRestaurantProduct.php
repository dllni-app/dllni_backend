<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantProducts\Pages;

use App\Filament\Resources\RestaurantProducts\RestaurantProductResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantProduct extends ViewRecord
{
    protected static string $resource = RestaurantProductResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل المنتج '.$this->record->name;
    }
}

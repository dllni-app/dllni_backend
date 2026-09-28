<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantInventoryItems\Pages;

use App\Filament\Resources\RestaurantInventoryItems\RestaurantInventoryItemResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantInventoryItem extends ViewRecord
{
    protected static string $resource = RestaurantInventoryItemResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل المخزون '.$this->record->name;
    }
}

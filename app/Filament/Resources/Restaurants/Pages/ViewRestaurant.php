<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\Pages;

use App\Filament\Resources\Restaurants\RestaurantResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurant extends ViewRecord
{
    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

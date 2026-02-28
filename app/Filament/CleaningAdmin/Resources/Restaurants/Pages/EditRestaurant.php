<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants\Pages;

use App\Filament\CleaningAdmin\Resources\Restaurants\RestaurantResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditRestaurant extends EditRecord
{
    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}

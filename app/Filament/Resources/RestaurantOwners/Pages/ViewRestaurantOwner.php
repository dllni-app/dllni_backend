<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOwners\Pages;

use App\Filament\Resources\RestaurantOwners\RestaurantOwnerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantOwner extends ViewRecord
{
    protected static string $resource = RestaurantOwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}


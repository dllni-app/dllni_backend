<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Pages;

use App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantOrderDispute extends ViewRecord
{
    protected static string $resource = RestaurantOrderDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

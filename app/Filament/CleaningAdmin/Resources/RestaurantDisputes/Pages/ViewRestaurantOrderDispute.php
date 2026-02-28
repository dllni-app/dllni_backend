<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantDisputes\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
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

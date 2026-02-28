<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\RestaurantSystemAlertResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantSystemAlert extends ViewRecord
{
    protected static string $resource = RestaurantSystemAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

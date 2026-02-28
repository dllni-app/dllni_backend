<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\RestaurantSystemAlertResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditRestaurantSystemAlert extends EditRecord
{
    protected static string $resource = RestaurantSystemAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}

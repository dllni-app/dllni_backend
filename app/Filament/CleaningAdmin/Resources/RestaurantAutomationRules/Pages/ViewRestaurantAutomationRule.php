<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\RestaurantAutomationRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewRestaurantAutomationRule extends ViewRecord
{
    protected static string $resource = RestaurantAutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

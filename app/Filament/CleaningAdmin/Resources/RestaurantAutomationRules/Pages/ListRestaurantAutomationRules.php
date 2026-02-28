<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\RestaurantAutomationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRestaurantAutomationRules extends ListRecords
{
    protected static string $resource = RestaurantAutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

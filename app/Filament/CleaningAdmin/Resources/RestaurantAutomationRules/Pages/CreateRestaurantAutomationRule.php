<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\RestaurantAutomationRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRestaurantAutomationRule extends CreateRecord
{
    protected static string $resource = RestaurantAutomationRuleResource::class;
}

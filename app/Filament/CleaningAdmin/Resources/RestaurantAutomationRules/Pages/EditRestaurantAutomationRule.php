<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\RestaurantAutomationRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditRestaurantAutomationRule extends EditRecord
{
    protected static string $resource = RestaurantAutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Pages;

use App\Filament\Resources\CleaningAutomationRules\CleaningAutomationRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningAutomationRule extends ViewRecord
{
    protected static string $resource = CleaningAutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

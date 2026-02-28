<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\CleaningAutomationRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningAutomationRule extends EditRecord
{
    protected static string $resource = CleaningAutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

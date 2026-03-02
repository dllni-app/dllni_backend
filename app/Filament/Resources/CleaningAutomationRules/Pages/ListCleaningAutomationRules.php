<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Pages;

use App\Filament\Resources\CleaningAutomationRules\CleaningAutomationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningAutomationRules extends ListRecords
{
    protected static string $resource = CleaningAutomationRuleResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.automation_rules.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

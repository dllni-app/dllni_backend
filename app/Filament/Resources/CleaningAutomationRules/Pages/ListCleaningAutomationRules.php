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
        return 'Create rules such as 100 completed hours within 2 months. The system only creates a pending member bonus; admin activation is required.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

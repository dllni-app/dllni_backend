<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\CleaningAutomationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningAutomationRules extends ListRecords
{
    protected static string $resource = CleaningAutomationRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'قواعد الأتمتة: مثلاً تعليق العامل عند انخفاض الثقة، أو منح شارة مميز عند تجاوز تقييم معين.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

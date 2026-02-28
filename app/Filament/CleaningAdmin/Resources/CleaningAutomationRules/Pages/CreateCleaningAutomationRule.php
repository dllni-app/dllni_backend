<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\CleaningAutomationRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningAutomationRule extends CreateRecord
{
    protected static string $resource = CleaningAutomationRuleResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Pages;

use App\Filament\Resources\CleaningAutomationRules\CleaningAutomationRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningAutomationRule extends CreateRecord
{
    protected static string $resource = CleaningAutomationRuleResource::class;
}

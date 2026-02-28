<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCleaningBillingPolicy extends ViewRecord
{
    protected static string $resource = CleaningBillingPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

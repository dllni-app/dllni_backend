<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCleaningBillingPolicies extends ListRecords
{
    protected static string $resource = CleaningBillingPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCleaningBillingPolicy extends EditRecord
{
    protected static string $resource = CleaningBillingPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

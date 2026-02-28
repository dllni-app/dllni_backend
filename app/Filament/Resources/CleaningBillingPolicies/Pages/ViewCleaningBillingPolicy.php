<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBillingPolicies\Pages;

use App\Filament\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningBillingPolicy extends ViewRecord
{
    protected static string $resource = CleaningBillingPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

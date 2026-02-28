<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBillingPolicies\Pages;

use App\Filament\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningBillingPolicy extends EditRecord
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

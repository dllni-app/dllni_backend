<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBillingPolicies\Pages;

use App\Filament\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningBillingPolicies extends ListRecords
{
    protected static string $resource = CleaningBillingPolicyResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.billing_policies.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

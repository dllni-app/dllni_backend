<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningBillingPolicy extends CreateRecord
{
    protected static string $resource = CleaningBillingPolicyResource::class;
}

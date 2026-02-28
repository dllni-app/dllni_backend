<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBillingPolicies\Pages;

use App\Filament\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningBillingPolicy extends CreateRecord
{
    protected static string $resource = CleaningBillingPolicyResource::class;
}

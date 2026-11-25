<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningCustomerIncidents\Pages;

use App\Filament\Resources\CleaningCustomerIncidents\CleaningCustomerIncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningCustomerIncidents extends ListRecords
{
    protected static string $resource = CleaningCustomerIncidentResource::class;
}

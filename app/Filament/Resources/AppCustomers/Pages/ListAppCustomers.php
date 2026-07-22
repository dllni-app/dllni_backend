<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\Pages;

use App\Filament\Resources\AppCustomers\AppCustomerResource;
use Filament\Resources\Pages\ListRecords;

final class ListAppCustomers extends ListRecords
{
    protected static string $resource = AppCustomerResource::class;
}

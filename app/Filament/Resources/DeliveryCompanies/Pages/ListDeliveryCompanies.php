<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryCompanies\Pages;

use App\Filament\Resources\DeliveryCompanies\DeliveryCompanyResource;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryCompanies extends ListRecords
{
    protected static string $resource = DeliveryCompanyResource::class;
}

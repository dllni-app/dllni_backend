<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanySettings\Pages;

use App\Filament\Company\Resources\DeliveryCompanySettings\DeliveryCompanySettingsResource;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryCompanySettings extends ListRecords
{
    protected static string $resource = DeliveryCompanySettingsResource::class;
}

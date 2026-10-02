<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDrivers\Pages;

use App\Filament\Resources\DeliveryDrivers\DeliveryDriverResource;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryDrivers extends ListRecords
{
    protected static string $resource = DeliveryDriverResource::class;

    public function getTitle(): string
    {
        return 'مندوبي التوصيل';
    }
}

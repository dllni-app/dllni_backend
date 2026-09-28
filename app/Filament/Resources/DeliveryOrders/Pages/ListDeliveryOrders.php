<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryOrders\Pages;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryOrders extends ListRecords
{
    protected static string $resource = DeliveryOrderResource::class;

    public function getTitle(): string
    {
        return 'طلبات التوصيل';
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryOrders\Pages;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewDeliveryOrder extends ViewRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل طلب التوصيل '.$this->record->order_number;
    }
}

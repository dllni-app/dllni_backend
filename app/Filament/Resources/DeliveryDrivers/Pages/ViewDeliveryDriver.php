<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDrivers\Pages;

use App\Filament\Resources\DeliveryDrivers\DeliveryDriverResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewDeliveryDriver extends ViewRecord
{
    protected static string $resource = DeliveryDriverResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل المندوب '.($this->record->first_name ?: '#'.$this->record->id);
    }
}

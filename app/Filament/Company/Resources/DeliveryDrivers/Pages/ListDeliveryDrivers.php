<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Pages;

use App\Filament\Company\Resources\DeliveryDrivers\DeliveryDriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryDrivers extends ListRecords
{
    protected static string $resource = DeliveryDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('delivery_company.drivers.actions.create')),
        ];
    }
}

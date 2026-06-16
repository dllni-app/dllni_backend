<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Pages;

use App\Filament\Company\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryOrders extends ListRecords
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('delivery_company.orders.actions.create')),
        ];
    }
}

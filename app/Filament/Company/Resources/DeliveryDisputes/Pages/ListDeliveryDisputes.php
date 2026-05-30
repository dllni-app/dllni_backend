<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes\Pages;

use App\Filament\Company\Resources\DeliveryDisputes\DeliveryDisputeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryDisputes extends ListRecords
{
    protected static string $resource = DeliveryDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('delivery_company.disputes.actions.create')),
        ];
    }
}

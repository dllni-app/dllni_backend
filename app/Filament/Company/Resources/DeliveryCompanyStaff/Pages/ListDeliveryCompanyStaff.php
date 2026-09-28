<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanyStaff\Pages;

use App\Filament\Company\Resources\DeliveryCompanyStaff\DeliveryCompanyStaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryCompanyStaff extends ListRecords
{
    protected static string $resource = DeliveryCompanyStaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('delivery_company.staff.actions.create')),
        ];
    }
}

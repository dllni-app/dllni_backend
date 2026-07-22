<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\Pages;

use App\Filament\Resources\AppCustomers\AppCustomerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewAppCustomer extends ViewRecord
{
    protected static string $resource = AppCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('تعديل'),
        ];
    }
}

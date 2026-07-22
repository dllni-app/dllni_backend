<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\Pages;

use App\Filament\Resources\AppCustomers\AppCustomerResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditAppCustomer extends EditRecord
{
    protected static string $resource = AppCustomerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['module_type'] = null;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('عرض'),
        ];
    }
}

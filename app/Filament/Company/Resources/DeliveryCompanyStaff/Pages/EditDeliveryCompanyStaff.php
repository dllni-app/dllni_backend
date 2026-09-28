<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanyStaff\Pages;

use App\Filament\Company\Resources\DeliveryCompanyStaff\DeliveryCompanyStaffResource;
use Filament\Resources\Pages\EditRecord;
use Modules\Delivery\Models\DeliveryCompanyStaff;

final class EditDeliveryCompanyStaff extends EditRecord
{
    protected static string $resource = DeliveryCompanyStaffResource::class;

    protected function afterSave(): void
    {
        /** @var DeliveryCompanyStaff $staff */
        $staff = $this->record;
        $staff->loadMissing('user');

        if ($staff->is_active) {
            $staff->user?->assignRole('delivery_company_staff');
        } else {
            $staff->user?->removeRole('delivery_company_staff');
            $staff->user?->tokens()->delete();
        }
    }

    protected function getRedirectUrl(): string
    {
        return DeliveryCompanyStaffResource::getUrl('index', panel: 'company');
    }
}

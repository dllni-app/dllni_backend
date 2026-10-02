<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanyStaff\Pages;

use App\Filament\Company\Resources\DeliveryCompanyStaff\DeliveryCompanyStaffResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\Delivery\Models\DeliveryCompanyStaff;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class CreateDeliveryCompanyStaff extends CreateRecord
{
    protected static string $resource = DeliveryCompanyStaffResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = app(DeliveryCompanyContextService::class)
            ->companyIdForUser(auth()->user());

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var DeliveryCompanyStaff $staff */
        $staff = $this->record;
        $staff->loadMissing('user');
        $staff->user?->assignRole('delivery_company_staff');
    }

    protected function getRedirectUrl(): string
    {
        return DeliveryCompanyStaffResource::getUrl('index', panel: 'company');
    }
}

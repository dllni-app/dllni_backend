<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Pages;

use App\Filament\Company\Resources\DeliveryDrivers\DeliveryDriverResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DeliveryCompanyContextService;
use Modules\Delivery\Services\FinancialLedgerService;

final class CreateDeliveryDriver extends CreateRecord
{
    protected static string $resource = DeliveryDriverResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());
        $data['availability_status'] = DeliveryDriverAvailabilityStatus::Offline->value;
        $data['is_active'] = true;
        $data['is_suspended'] = false;
        $data['trust_score'] = (int) config('delivery.trust.default_score', 100);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var DeliveryDriver $driver */
        $driver = $this->record;

        app(FinancialLedgerService::class)->ensureAccount($driver);
    }

    protected function getRedirectUrl(): string
    {
        /** @var DeliveryDriver $record */
        $record = $this->record;

        return DeliveryDriverResource::getUrl('view', ['record' => $record], panel: 'company');
    }
}

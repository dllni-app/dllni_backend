<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Pages;

use App\Filament\Company\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;
use Modules\Delivery\Services\DeliveryOrderService;

final class CreateDeliveryOrder extends CreateRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $company = app(DeliveryCompanyContextService::class)->resolveFromUser(auth()->user());

        try {
            return app(DeliveryOrderService::class)->create($company, [
                'customerName' => (string) $data['customer_name'],
                'customerPhone' => $data['customer_phone'] ?? null,
                'customerNotes' => $data['customer_notes'] ?? null,
                'pickupAddress' => (string) $data['pickup_address'],
                'pickupLatitude' => (float) $data['pickup_latitude'],
                'pickupLongitude' => (float) $data['pickup_longitude'],
                'dropoffAddress' => (string) $data['dropoff_address'],
                'dropoffLatitude' => (float) $data['dropoff_latitude'],
                'dropoffLongitude' => (float) $data['dropoff_longitude'],
            ], auth()->id());
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            throw $exception;
        }
    }

    protected function getRedirectUrl(): string
    {
        /** @var DeliveryOrder $record */
        $record = $this->record;

        return DeliveryOrderResource::getUrl('view', ['record' => $record]);
    }
}

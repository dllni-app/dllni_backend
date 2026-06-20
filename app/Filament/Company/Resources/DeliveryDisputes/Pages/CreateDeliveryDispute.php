<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Company\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Models\Dispute;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class CreateDeliveryDispute extends CreateRecord
{
    protected static string $resource = DeliveryDisputeResource::class;

    public function mount(): void
    {
        parent::mount();

        $orderId = request()->query('order');

        if ($orderId !== null) {
            $this->form->fill([
                'booking_id' => (int) $orderId,
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());
        $orderId = (int) ($data['booking_id'] ?? 0);

        $orderExists = DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->whereKey($orderId)
            ->exists();

        if (! $orderExists) {
            throw ValidationException::withMessages([
                'booking_id' => 'الطلب المحدد لا يتبع لشركتك.',
            ]);
        }

        $data['booking_type'] = 'delivery_order';
        $data['status'] = DisputeStatus::Open->value;
        $data['ticket_number'] = 'DEL-DSP-'.mb_strtoupper(Str::random(8));
        $data['worker_earnings_frozen'] = false;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        /** @var Dispute $record */
        $record = $this->record;

        return DeliveryDisputeResource::getUrl('view', ['record' => $record], panel: 'company');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('delivery_company.disputes.actions.create'))
            ->success();
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Pages;

use App\Filament\Company\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Filament\Company\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;

final class ViewDeliveryOrder extends ViewRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_dispute')
                ->label(__('delivery_company.disputes.actions.open_for_order'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('delivery_disputes.create') ?? false)
                ->url(fn (): string => DeliveryDisputeResource::getUrl('create', [
                    'order' => $this->record->getKey(),
                ], panel: 'company')),
            Action::make('retry_dispatch')
                ->label(__('delivery_company.orders.actions.retry_dispatch'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('retryDispatch', $this->record) === true
                    && $this->record instanceof DeliveryOrder
                    && in_array($this->record->status, [
                        DeliveryOrderStatus::Stopped->value,
                        DeliveryOrderStatus::Dispatching->value,
                    ], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(DeliveryOrderService::class)->retryDispatch($this->record);
                        $this->refreshFormData(['status', 'stop_reason', 'stopped_at']);
                        Notification::make()
                            ->title(__('delivery_company.orders.actions.retry_dispatch'))
                            ->success()
                            ->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('cancel')
                ->label(__('delivery_company.orders.actions.cancel'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('cancel', $this->record) === true
                    && $this->record instanceof DeliveryOrder
                    && ! in_array($this->record->status, [
                        DeliveryOrderStatus::Delivered->value,
                        DeliveryOrderStatus::Completed->value,
                        DeliveryOrderStatus::Cancelled->value,
                    ], true))
                ->requiresConfirmation()
                ->form([
                    Textarea::make('cancel_reason')
                        ->label(__('delivery_company.orders.actions.cancel_reason'))
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(DeliveryOrderService::class)->cancel(
                            $this->record,
                            (string) $data['cancel_reason'],
                            auth()->id(),
                        );
                        $this->refreshFormData(['status', 'cancel_reason', 'cancelled_at']);
                        Notification::make()
                            ->title(__('delivery_company.orders.actions.cancel'))
                            ->success()
                            ->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}

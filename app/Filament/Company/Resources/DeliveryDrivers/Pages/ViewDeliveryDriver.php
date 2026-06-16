<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Pages;

use App\Filament\Company\Resources\DeliveryDrivers\DeliveryDriverResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliverySuspensionReason;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DriverManagementService;

final class ViewDeliveryDriver extends ViewRecord
{
    protected static string $resource = DeliveryDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label(__('delivery_company.drivers.actions.suspend'))
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => $this->record instanceof DeliveryDriver
                    && auth()->user()?->can('suspend', $this->record) === true
                    && ! $this->record->is_suspended
                    && $this->record->is_active)
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')
                        ->label(__('delivery_company.drivers.actions.suspend_reason'))
                        ->default(DeliverySuspensionReason::Manual->value)
                        ->required(),
                    DateTimePicker::make('suspended_until')
                        ->label(__('delivery_company.drivers.actions.suspend_until')),
                ])
                ->action(function (array $data): void {
                    try {
                        app(DriverManagementService::class)->suspend(
                            $this->record,
                            (string) $data['reason'],
                            $data['suspended_until'] ?? null,
                        );
                        $this->refreshFormData(['is_suspended', 'suspension_reason', 'suspended_until', 'availability_status']);
                        Notification::make()->title(__('delivery_company.drivers.actions.suspend'))->success()->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('unsuspend')
                ->label(__('delivery_company.drivers.actions.unsuspend'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof DeliveryDriver
                    && auth()->user()?->can('unsuspend', $this->record) === true
                    && $this->record->is_suspended
                    && $this->record->suspension_reason !== DeliverySuspensionReason::Financial->value)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(DriverManagementService::class)->unsuspend($this->record);
                        $this->refreshFormData(['is_suspended', 'suspension_reason', 'suspended_until', 'availability_status']);
                        Notification::make()->title(__('delivery_company.drivers.actions.unsuspend'))->success()->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('activate')
                ->label(__('delivery_company.drivers.actions.activate'))
                ->icon('heroicon-o-play')
                ->color('info')
                ->visible(fn (): bool => $this->record instanceof DeliveryDriver
                    && auth()->user()?->can('update', $this->record) === true
                    && ! $this->record->is_active)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(DriverManagementService::class)->activate($this->record);
                        $this->refreshFormData(['is_active', 'availability_status']);
                        Notification::make()->title(__('delivery_company.drivers.actions.activate'))->success()->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('deactivate')
                ->label(__('delivery_company.drivers.actions.deactivate'))
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (): bool => $this->record instanceof DeliveryDriver
                    && auth()->user()?->can('update', $this->record) === true
                    && $this->record->is_active)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(DriverManagementService::class)->deactivate($this->record);
                        $this->refreshFormData(['is_active', 'availability_status']);
                        Notification::make()->title(__('delivery_company.drivers.actions.deactivate'))->success()->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}

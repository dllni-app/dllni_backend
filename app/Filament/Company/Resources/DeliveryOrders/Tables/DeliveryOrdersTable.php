<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Tables;

use App\Filament\Company\Resources\DeliveryDisputes\DeliveryDisputeResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;

final class DeliveryOrdersTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = collect(DeliveryOrderStatus::cases())
            ->mapWithKeys(fn (DeliveryOrderStatus $status): array => [
                $status->value => __('delivery_company.orders.enums.status.'.$status->value),
            ])
            ->all();

        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('delivery_company.orders.fields.order_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label(__('delivery_company.orders.fields.customer_name'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('delivery_company.orders.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __('delivery_company.orders.enums.status.'.$state)
                        : '—'),
                TextColumn::make('driver.first_name')
                    ->label(__('delivery_company.orders.fields.driver'))
                    ->placeholder('—'),
                TextColumn::make('distance_km')
                    ->label(__('delivery_company.orders.fields.distance_km'))
                    ->sortable(),
                TextColumn::make('delivery_fee')
                    ->label(__('delivery_company.orders.fields.delivery_fee'))
                    ->money(fn (DeliveryOrder $record): string => $record->currency ?? config('delivery.pricing.default_currency', 'SYP')),
                TextColumn::make('created_at')
                    ->label(__('delivery_company.orders.fields.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('delivery_company.orders.fields.status'))
                    ->options($statusOptions),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('driver'))
            ->recordActions([
                ViewAction::make(),
                Action::make('open_dispute')
                    ->label(__('delivery_company.disputes.actions.open_for_order'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (): bool => auth()->user()?->can('delivery_disputes.create') ?? false)
                    ->url(fn (DeliveryOrder $record): string => DeliveryDisputeResource::getUrl('create', [
                        'order' => $record->getKey(),
                    ], panel: 'company')),
                Action::make('retry_dispatch')
                    ->label(__('delivery_company.orders.actions.retry_dispatch'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (DeliveryOrder $record): bool => auth()->user()?->can('retryDispatch', $record) === true
                        && in_array($record->status, [
                            DeliveryOrderStatus::Stopped->value,
                            DeliveryOrderStatus::Dispatching->value,
                        ], true))
                    ->requiresConfirmation()
                    ->action(function (DeliveryOrder $record): void {
                        try {
                            app(DeliveryOrderService::class)->retryDispatch($record);
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
                    ->visible(fn (DeliveryOrder $record): bool => auth()->user()?->can('cancel', $record) === true
                        && ! in_array($record->status, [
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
                    ->action(function (DeliveryOrder $record, array $data): void {
                        try {
                            app(DeliveryOrderService::class)->cancel(
                                $record,
                                (string) $data['cancel_reason'],
                                auth()->id(),
                            );
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
            ])
            ->defaultSort('created_at', 'desc');
    }
}

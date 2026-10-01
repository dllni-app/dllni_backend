<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryOrders\Tables;

use App\Filament\Support\AdminDeliveryLabels;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_type')
                    ->label('المصدر')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'restaurant_order' => 'مطعم',
                        'supermarket_order' => 'سوبرماركت',
                        null, '' => 'مستقل',
                        default => $state,
                    }),
                TextColumn::make('company.name')
                    ->label('شركة التوصيل')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('driver.first_name')
                    ->label('المندوب')
                    ->searchable()
                    ->placeholder('غير معيّن'),
                TextColumn::make('customer_name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __('delivery_company.orders.enums.status.'.$state)
                        : '—'),
                TextColumn::make('dispatch_phase')
                    ->label('مرحلة الإسناد')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::dispatchPhase($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('distance_km')
                    ->label('المسافة')
                    ->suffix(' كم')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('delivery_fee')
                    ->label('رسوم التوصيل')
                    ->money(fn (DeliveryOrder $record): string => $record->currency ?? config('delivery.pricing.default_currency', 'SYP'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options($statusOptions),
                SelectFilter::make('company_id')
                    ->label('شركة التوصيل')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('source_type')
                    ->label('المصدر')
                    ->options([
                        'restaurant_order' => 'مطعم',
                        'supermarket_order' => 'سوبرماركت',
                    ]),
                TernaryFilter::make('driver_id')
                    ->label('حالة تعيين المندوب')
                    ->placeholder('الكل')
                    ->trueLabel('تم تعيين مندوب')
                    ->falseLabel('بدون مندوب')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('driver_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('driver_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company',
                'driver',
                'source',
            ]))
            ->recordActions([
                ViewAction::make(),
                Action::make('retry_dispatch')
                    ->label('إعادة محاولة الإسناد')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (DeliveryOrder $record): bool => self::canIntervene()
                        && in_array($record->status, [
                            DeliveryOrderStatus::Stopped->value,
                            DeliveryOrderStatus::Dispatching->value,
                        ], true))
                    ->requiresConfirmation()
                    ->action(function (DeliveryOrder $record): void {
                        try {
                            app(DeliveryOrderService::class)->retryDispatch($record);
                            Notification::make()
                                ->title('تمت إعادة محاولة الإسناد')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(\App\Filament\Support\AdminExceptionMessage::forUser($exception))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('إلغاء إداري')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DeliveryOrder $record): bool => self::canIntervene()
                        && ! in_array($record->status, [
                            DeliveryOrderStatus::Delivered->value,
                            DeliveryOrderStatus::Completed->value,
                            DeliveryOrderStatus::Cancelled->value,
                        ], true))
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('cancel_reason')
                            ->label('سبب الإلغاء')
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
                                ->title('تم إلغاء طلب التوصيل')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title(\App\Filament\Support\AdminExceptionMessage::forUser($exception))
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function canIntervene(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin'])
                || $user->can('platform_delivery_operations.update'));
    }
}

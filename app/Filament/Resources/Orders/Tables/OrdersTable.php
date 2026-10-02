<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Models\Order;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $case): array => [
                $case->value => __('restaurant_admin.enums.order_status.'.$case->value),
            ])
            ->all();

        return $table
            ->searchPlaceholder('ابحث برقم الطلب، اسم العميل، هاتف العميل أو المطعم')
            ->columns([
                TextColumn::make('order_number')->label('رقم الطلب')->searchable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('user.name')->label('العميل')->searchable(),
                TextColumn::make('user.phone')->label('هاتف العميل')->searchable()->copyable()->toggleable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state?->value ?? $state;

                        return $value ? __('restaurant_admin.enums.order_status.'.$value) : '—';
                    }),
                TextColumn::make('order_type')
                    ->label('نوع التنفيذ')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof OrderType ? $state->value : $state;

                        return match ($value) {
                            'delivery' => 'توصيل',
                            'pickup' => 'استلام',
                            'dine_in' => 'داخل المطعم',
                            default => $value ?? '—',
                        };
                    }),
                TextColumn::make('deliveryOrder.status')
                    ->label('حالة التوصيل')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __('delivery_company.orders.enums.status.'.$state)
                        : '—')
                    ->toggleable(),
                TextColumn::make('attention')
                    ->label('الانتباه')
                    ->state(fn (Order $record): string => self::attentionLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'طبيعي' ? 'success' : 'danger'),
                TextColumn::make('estimated_ready_at')
                    ->label('الجاهزية المتوقعة')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total_amount')->label('المجموع')->money(config('app.currency', 'SYP')),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'restaurant',
                'user',
                'deliveryOrder',
                'disputes',
            ]))
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options($statusOptions),
                SelectFilter::make('restaurant_id')
                    ->label('المطعم')
                    ->relationship('restaurant', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('order_type')
                    ->label('نوع التنفيذ')
                    ->options([
                        OrderType::Delivery->value => 'توصيل',
                        OrderType::Pickup->value => 'استلام',
                        OrderType::DineIn->value => 'داخل المطعم',
                    ]),
                Filter::make('created_today')
                    ->label('طلبات اليوم')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),
                Filter::make('late_preparation')
                    ->label('تحضير متأخر')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [
                            OrderStatus::Accepted->value,
                            OrderStatus::Preparing->value,
                        ])
                        ->whereNotNull('estimated_ready_at')
                        ->where('estimated_ready_at', '<', now())),
                Filter::make('ready_waiting_pickup')
                    ->label('جاهز وينتظر الاستلام')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', OrderStatus::ReadyForPickup->value)
                        ->whereNotNull('ready_for_pickup_at')),
                Filter::make('has_dispute')
                    ->label('يوجد نزاع')
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                Filter::make('has_delivery_issue')
                    ->label('مشكلة توصيل')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'deliveryOrder',
                        fn (Builder $delivery): Builder => $delivery->where(function (Builder $problem): void {
                            $problem
                                ->whereIn('status', [
                                    DeliveryOrderStatus::Stopped->value,
                                    DeliveryOrderStatus::Cancelled->value,
                                ])
                                ->orWhere(function (Builder $dispatching): void {
                                    $dispatching
                                        ->where('status', DeliveryOrderStatus::Dispatching->value)
                                        ->whereNull('driver_id');
                                });
                        }),
                    )),
                Filter::make('created_at')
                    ->label('نطاق التاريخ')
                    ->form([
                        DatePicker::make('from')->label('من')->native(false),
                        DatePicker::make('to')->label('إلى')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['to'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function attentionLabel(Order $record): string
    {
        $status = $record->status?->value ?? $record->status;

        if (
            in_array($status, [OrderStatus::Accepted->value, OrderStatus::Preparing->value], true)
            && $record->estimated_ready_at?->isPast()
        ) {
            return 'تحضير متأخر';
        }

        if ($record->disputes->isNotEmpty()) {
            return 'نزاع';
        }

        $delivery = $record->deliveryOrder;
        if ($delivery !== null) {
            if (in_array($delivery->status, [
                DeliveryOrderStatus::Stopped->value,
                DeliveryOrderStatus::Cancelled->value,
            ], true)) {
                return 'مشكلة توصيل';
            }

            if ($delivery->status === DeliveryOrderStatus::Dispatching->value && $delivery->driver_id === null) {
                return 'بانتظار مندوب';
            }
        }

        if ($status === OrderStatus::ReadyForPickup->value && $record->ready_for_pickup_at !== null) {
            return 'جاهز للاستلام';
        }

        return 'طبيعي';
    }
}

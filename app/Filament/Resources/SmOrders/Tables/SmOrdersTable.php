<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Tables;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

final class SmOrdersTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = collect(SmOrderStatus::cases())->mapWithKeys(
            fn (SmOrderStatus $case): array => [
                $case->value => __('supermarket_admin.enums.order_status.'.$case->value),
            ]
        )->all();

        return $table
            ->searchPlaceholder('ابحث برقم الطلب، اسم العميل، هاتف العميل أو اسم المتجر')
            ->columns([
                TextColumn::make('order_number')->label('رقم الطلب')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('العميل')->searchable()->placeholder('—'),
                TextColumn::make('customer.phone')->label('هاتف العميل')->searchable()->copyable()->placeholder('—')->toggleable(),
                TextColumn::make('store.name')->label('المتجر')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state): string => $state
                        ? __('supermarket_admin.enums.order_status.'.$state->value)
                        : '—')
                    ->badge()
                    ->sortable(),
                TextColumn::make('fulfillment')
                    ->label('نوع التنفيذ')
                    ->state(fn (SmOrder $record): string => $record->deliveryOrder !== null ? 'توصيل' : 'استلام')
                    ->badge(),
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
                    ->state(fn (SmOrder $record): string => self::attentionLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'طبيعي' ? 'success' : 'danger'),
                TextColumn::make('estimated_ready_at')
                    ->label('الجاهزية المتوقعة')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ready_for_pickup_at')
                    ->label('جاهز منذ')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('الإجمالي')
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'customer',
                'store',
                'deliveryOrder',
                'disputes',
            ]))
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options($statusOptions),
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label('المتجر')
                    ->searchable()
                    ->preload(),
                Filter::make('delivery')
                    ->label('طلبات التوصيل')
                    ->query(fn (Builder $query): Builder => $query->whereHas('deliveryOrder')),
                Filter::make('pickup')
                    ->label('استلام من المتجر')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('deliveryOrder')),
                Filter::make('created_today')
                    ->label('طلبات اليوم')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),
                Filter::make('late_preparation')
                    ->label('تحضير متأخر')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [
                            SmOrderStatus::Accepted->value,
                            SmOrderStatus::Preparing->value,
                        ])
                        ->whereNotNull('estimated_ready_at')
                        ->where('estimated_ready_at', '<', now())),
                Filter::make('ready_waiting_pickup')
                    ->label('جاهز وينتظر الاستلام')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', SmOrderStatus::ReadyForPickup->value)
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
                ViewAction::make()->label('عرض'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function attentionLabel(SmOrder $record): string
    {
        $status = $record->status?->value ?? $record->status;

        if (
            in_array($status, [SmOrderStatus::Accepted->value, SmOrderStatus::Preparing->value], true)
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

        if ($status === SmOrderStatus::ReadyForPickup->value && $record->ready_for_pickup_at !== null) {
            return 'جاهز للاستلام';
        }

        return 'طبيعي';
    }
}

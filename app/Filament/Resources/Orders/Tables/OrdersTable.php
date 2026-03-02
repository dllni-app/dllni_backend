<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Resturants\Enums\OrderStatus;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = collect(OrderStatus::cases())->mapWithKeys(fn($c) => [$c->value => __('restaurant_admin.enums.order_status.' . $c->value)])->all();

        return $table
            ->columns([
                TextColumn::make('order_number')->label('رقم الطلب')->searchable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('user.name')->label('العميل')->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state?->value ?? $state;

                        return $value ? __('restaurant_admin.enums.order_status.' . $value) : '—';
                    }),
                TextColumn::make('order_type')->label('نوع الطلب')->formatStateUsing(fn(?string $state): string => $state ?? '—'),
                TextColumn::make('accepted_at')->label('قبول')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('preparing_at')->label('تحضير')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('completed_at')->label('إكمال')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('cancelled_at')->label('إلغاء')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('total_amount')->label('المجموع')->money('SAR'),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options($statusOptions),
                SelectFilter::make('restaurant_id')->label('المطعم')->relationship('restaurant', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

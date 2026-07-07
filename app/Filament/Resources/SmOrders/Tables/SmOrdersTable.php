<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Tables;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Supermarket\Enums\SmOrderStatus;

final class SmOrdersTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = collect(SmOrderStatus::cases())->mapWithKeys(
            fn (SmOrderStatus $c) => [$c->value => __('supermarket_admin.enums.order_status.'.$c->value)]
        )->all();

        return $table
            ->searchPlaceholder('ابحث برقم الطلب، اسم العميل أو اسم المتجر')
            ->columns([
                TextColumn::make('order_number')->label(__('supermarket_admin.infolist.order_number'))->searchable()->sortable(),
                TextColumn::make('customer.name')->label(__('supermarket_admin.infolist.order_customer'))->searchable()->placeholder('—'),
                TextColumn::make('store.name')->label(__('supermarket_admin.stores'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('supermarket_admin.form.status'))
                    ->formatStateUsing(fn ($state) => $state ? __('supermarket_admin.enums.order_status.'.$state->value) : '—')
                    ->badge()
                    ->sortable(),
                TextColumn::make('pickup_scheduled_for')->label(__('supermarket_admin.infolist.pickup_scheduled'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('ready_for_pickup_at')->label(__('supermarket_admin.infolist.ready_at'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('picked_up_at')->label(__('supermarket_admin.infolist.picked_up_at'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label(__('supermarket_admin.infolist.total_amount'))
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('cancellation_fee_amount')
                    ->label(__('supermarket_admin.infolist.cancellation_fee'))
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'store']))
            ->filters([
                SelectFilter::make('status')->label(__('supermarket_admin.form.status'))->options($statusOptions),
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
            ]);
    }
}

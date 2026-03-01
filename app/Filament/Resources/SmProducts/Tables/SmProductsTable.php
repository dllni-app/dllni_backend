<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Supermarket\Enums\SmProductSource;

final class SmProductsTable
{
    public static function configure(Table $table): Table
    {
        $sourceOptions = collect(SmProductSource::cases())->mapWithKeys(
            fn (SmProductSource $c) => [$c->value => __('supermarket_admin.enums.product_source.'.$c->value)]
        )->all();

        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('supermarket_admin.stores'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('name')->label(__('supermarket_admin.infolist.product_name'))->searchable()->sortable(),
                TextColumn::make('source_type')
                    ->label(__('supermarket_admin.form.product_source'))
                    ->formatStateUsing(fn ($state) => $state ? __('supermarket_admin.enums.product_source.'.$state->value) : '—')
                    ->sortable(),
                TextColumn::make('price')->label(__('supermarket_admin.form.price'))->money(config('app.currency', 'IQD'))->sortable(),
                TextColumn::make('discounted_price')->label(__('supermarket_admin.form.discounted_price'))->money(config('app.currency', 'IQD'))->placeholder('—')->sortable(),
                TextColumn::make('stock_quantity')->label(__('supermarket_admin.form.stock_quantity'))->sortable(),
                TextColumn::make('low_stock_threshold')->label(__('supermarket_admin.form.low_stock_threshold'))->sortable(),
                TextColumn::make('expires_at')->label(__('supermarket_admin.form.expires_at'))->dateTime('Y-m-d')->placeholder('—')->sortable(),
                IconColumn::make('is_available')->label(__('supermarket_admin.form.is_active'))->boolean(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->filters([
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_available')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->options([1 => __('supermarket_admin.enums.boolean.yes'), 0 => __('supermarket_admin.enums.boolean.no')]),
                SelectFilter::make('source_type')->label(__('supermarket_admin.form.product_source'))->options($sourceOptions),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

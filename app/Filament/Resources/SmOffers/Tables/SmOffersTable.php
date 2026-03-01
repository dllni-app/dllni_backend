<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SmOffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('supermarket_admin.stores'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('name')->label(__('supermarket_admin.infolist.product_name'))->searchable()->sortable(),
                IconColumn::make('is_active')->label(__('supermarket_admin.form.is_active'))->boolean(),
                TextColumn::make('starts_at')->label(__('supermarket_admin.form.starts_at'))->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
                TextColumn::make('ends_at')->label(__('supermarket_admin.form.ends_at'))->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->filters([
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SmStoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('supermarket_admin.stores'))->searchable()->sortable(),
                TextColumn::make('owner.name')->label(__('supermarket_admin.infolist.owner'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('trust_score')->label(__('supermarket_admin.infolist.trust_score'))->sortable(),
                TextColumn::make('warning_count')->label(__('supermarket_admin.infolist.warning_count'))->sortable(),
                TextColumn::make('average_rating')->label(__('supermarket_admin.infolist.average_rating'))->sortable(),
                TextColumn::make('total_reviews')->label(__('supermarket_admin.infolist.total_reviews'))->sortable(),
                IconColumn::make('is_active')->label(__('supermarket_admin.form.is_active'))->boolean(),
                IconColumn::make('is_featured')->label(__('supermarket_admin.form.is_featured'))->boolean(),
                TextColumn::make('suspension_until')->label(__('supermarket_admin.form.suspension_until'))->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('owner'))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

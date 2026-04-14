<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SmCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('supermarket_admin.form.category_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('supermarket_admin.form.category_slug'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('supermarket_admin.form.sort_order'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('supermarket_admin.infolist.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('supermarket_admin.infolist.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->filters([
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->options([
                        1 => __('supermarket_admin.enums.boolean.yes'),
                        0 => __('supermarket_admin.enums.boolean.no'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class MasterProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('supermarket_admin.form.master_category_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('supermarket_admin.form.master_category_slug'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('supermarket_admin.form.sort_order'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('master_products_count')
                    ->label(__('supermarket_admin.infolist.master_products_count'))
                    ->counts('masterProducts')
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
            ->filters([
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

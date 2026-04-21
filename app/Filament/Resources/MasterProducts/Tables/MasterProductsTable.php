<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Tables;

use App\Enums\MasterProductUnit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class MasterProductsTable
{
    public static function configure(Table $table): Table
    {
        $unitOptions = collect(MasterProductUnit::cases())->mapWithKeys(
            fn (MasterProductUnit $unit): array => [$unit->value => __('supermarket_admin.enums.master_product_unit.'.$unit->value)]
        )->all();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('supermarket_admin.form.master_product_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label(__('supermarket_admin.form.master_product_unit'))
                    ->badge()
                    ->formatStateUsing(fn (?MasterProductUnit $state): string => $state
                        ? __('supermarket_admin.enums.master_product_unit.'.$state->value)
                        : '—')
                    ->sortable(),
                TextColumn::make('brand')
                    ->label(__('supermarket_admin.form.master_product_brand'))
                    ->searchable()
                    ->placeholder('—'),
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
                SelectFilter::make('unit')
                    ->label(__('supermarket_admin.form.master_product_unit'))
                    ->options($unitOptions),
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

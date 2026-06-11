<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningExtendedTimePrices\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CleaningExtendedTimePricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('cleaning_admin.extended_time_prices.fields.sort_order'))
                    ->sortable(),
                TextColumn::make('start_minutes')
                    ->label(__('cleaning_admin.extended_time_prices.fields.start_minutes')),
                TextColumn::make('end_minutes')
                    ->label(__('cleaning_admin.extended_time_prices.fields.end_minutes')),
                TextColumn::make('price')
                    ->label(__('cleaning_admin.extended_time_prices.fields.price'))
                    ->money(config('app.currency', 'SYP')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('cleaning_admin.shared.actions.edit')),
            ]);
    }
}

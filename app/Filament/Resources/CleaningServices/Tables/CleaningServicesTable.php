<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class CleaningServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cleaning_admin.cleaning_services.fields.name'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.cleaning_services.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('cleaning_admin.cleaning_services.filters.is_active')),
            ])
            ->recordActions([
                ViewAction::make()->label(__('cleaning_admin.shared.actions.view')),
                EditAction::make()->label(__('cleaning_admin.shared.actions.edit')),
            ]);
    }
}

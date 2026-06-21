<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Tables;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class CleaningNeighborhoodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.name_ar'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_en')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.name_en'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('city_name')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.city_name'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('aliases')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.aliases'))
                    ->formatStateUsing(fn ($state): string => implode(', ', is_array($state) ? $state : []))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.sort_order'))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('cleaning_admin.shared.actions.edit'))
                    ->url(fn (CleaningNeighborhood $record): string => CleaningNeighborhoodResource::getUrl('edit', ['record' => $record])),
                Action::make('toggle_active')
                    ->label(fn (CleaningNeighborhood $record): string => $record->is_active
                        ? __('cleaning_admin.cleaning_neighborhoods.actions.deactivate')
                        : __('cleaning_admin.cleaning_neighborhoods.actions.activate'))
                    ->color(fn (CleaningNeighborhood $record): string => $record->is_active ? 'warning' : 'success')
                    ->action(function (CleaningNeighborhood $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate_selected')
                        ->label(__('cleaning_admin.cleaning_neighborhoods.actions.activate_selected'))
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true])),
                    BulkAction::make('deactivate_selected')
                        ->label(__('cleaning_admin.cleaning_neighborhoods.actions.deactivate_selected'))
                        ->color('warning')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false])),
                ]),
            ]);
    }
}

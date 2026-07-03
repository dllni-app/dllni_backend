<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Tables;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use App\Models\CleaningFinancialSetting;
use App\Models\WorkerZone;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class CleaningNeighborhoodsTable
{
    public static function configure(Table $table): Table
    {
        $thresholds = CleaningFinancialSetting::query()->first()?->coverage_thresholds ?? ['low' => 3, 'ok' => 7];
        $highCoverageThreshold = (int) ($thresholds['ok'] ?? 7);

        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'workers_count' => WorkerZone::query()
                    ->selectRaw('COUNT(DISTINCT worker_id)')
                    ->whereColumn('neighborhood_id', 'cleaning_neighborhoods.id')
                    ->where('is_active', true),
            ]))
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.name_ar'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city_name')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.city_name'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('workers_count')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.workers_count'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('coverage_level')
                    ->label(__('cleaning_admin.cleaning_neighborhoods.fields.coverage_level'))
                    ->badge()
                    ->getStateUsing(fn (CleaningNeighborhood $record): string => (int) ($record->workers_count ?? 0) >= $highCoverageThreshold ? 'high' : 'low')
                    ->formatStateUsing(fn (string $state): string => __('cleaning_admin.cleaning_neighborhoods.coverage.'.$state))
                    ->color(fn (string $state): string => $state === 'high' ? 'success' : 'warning'),
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

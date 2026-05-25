<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDailyStats\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SmStoreDailyStatsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('supermarket_admin.stores'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('date')->label(__('supermarket_admin.infolist.date'))->date('Y-m-d')->sortable(),
                TextColumn::make('orders_count')->label(__('supermarket_admin.infolist.orders_count'))->sortable(),
                TextColumn::make('orders_revenue')->label(__('supermarket_admin.infolist.orders_revenue'))->money(config('app.currency', 'SYP'))->sortable(),
                TextColumn::make('unique_customers')->label(__('supermarket_admin.infolist.unique_customers'))->sortable(),
                TextColumn::make('new_customers')->label(__('supermarket_admin.infolist.new_customers'))->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->filters([
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->preload(),
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('date_from')->label(__('supermarket_admin.form.starts_at')),
                        DatePicker::make('date_until')->label(__('supermarket_admin.form.ends_at')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['date_from'] ?? null, fn (Builder $q, $date) => $q->where('date', '>=', $date))
                        ->when($data['date_until'] ?? null, fn (Builder $q, $date) => $q->where('date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

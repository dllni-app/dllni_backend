<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreTrustLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SmStoreTrustLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('supermarket_admin.infolist.name'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('event_type')->label(__('supermarket_admin.infolist.event_type'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('score_delta')->label(__('supermarket_admin.infolist.score_delta'))->sortable()->placeholder('—'),
                TextColumn::make('score_after')->label(__('supermarket_admin.infolist.score_after'))->sortable()->placeholder('—'),
                TextColumn::make('notes')->label(__('supermarket_admin.infolist.notes'))->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->label(__('supermarket_admin.infolist.created_at'))->dateTime('Y-m-d H:i')->sortable(),
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
            ]);
    }
}

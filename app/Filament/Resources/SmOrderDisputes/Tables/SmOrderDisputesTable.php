<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Supermarket\Enums\SmDisputeStatus;

final class SmOrderDisputesTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = collect(SmDisputeStatus::cases())->mapWithKeys(
            fn (SmDisputeStatus $c) => [$c->value => __('supermarket_admin.enums.dispute_status.'.$c->value)]
        )->all();

        return $table
            ->columns([
                TextColumn::make('ticket_number')->label(__('supermarket_admin.form.ticket_number'))->searchable()->sortable(),
                TextColumn::make('order.order_number')->label(__('supermarket_admin.infolist.order_number'))->searchable()->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('supermarket_admin.form.status'))
                    ->formatStateUsing(fn ($state) => $state ? __('supermarket_admin.enums.dispute_status.'.$state->value) : '—')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')->label(__('supermarket_admin.infolist.created_at'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('order'))
            ->filters([
                SelectFilter::make('status')->label(__('supermarket_admin.form.status'))->options($statusOptions),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

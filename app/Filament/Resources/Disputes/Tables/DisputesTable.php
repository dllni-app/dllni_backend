<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Tables;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')
                    ->label(__('cleaning_admin.disputes.fields.ticket_number'))
                    ->description(__('cleaning_admin.column_descriptions.ticket_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('booking_number')
                    ->label(__('cleaning_admin.disputes.fields.booking_number'))
                    ->description(__('cleaning_admin.column_descriptions.booking_number'))
                    ->getStateUsing(fn ($record) => $record->booking?->booking_number ?? '-')
                    ->placeholder('-'),
                TextColumn::make('category')
                    ->label(__('cleaning_admin.disputes.fields.category'))
                    ->description(__('cleaning_admin.column_descriptions.category'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('status')
                    ->label(__('cleaning_admin.disputes.fields.status'))
                    ->description(__('cleaning_admin.column_descriptions.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('resolution')
                    ->label(__('cleaning_admin.disputes.fields.resolution'))
                    ->description(__('cleaning_admin.column_descriptions.resolution'))
                    ->placeholder('-')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('created_at')
                    ->label(__('cleaning_admin.disputes.fields.created_at'))
                    ->description(__('cleaning_admin.column_descriptions.created_at'))
                    ->since()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('booking'))
            ->filters([
                SelectFilter::make('status')->label(__('cleaning_admin.disputes.fields.status'))->options(collect(DisputeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('category')->label(__('cleaning_admin.disputes.fields.category'))->options(collect(DisputeCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('resolution')->label(__('cleaning_admin.disputes.fields.resolution'))->options(collect(DisputeResolution::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label(__('cleaning_admin.workers.view')),
                EditAction::make()->label(__('cleaning_admin.workers.edit')),
            ]);
    }
}

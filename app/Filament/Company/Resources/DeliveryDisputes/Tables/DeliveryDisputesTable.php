<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes\Tables;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DeliveryDisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')
                    ->label(__('delivery_company.disputes.fields.ticket_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('booking.order_number')
                    ->label(__('delivery_company.disputes.fields.order_number'))
                    ->placeholder('—'),
                TextColumn::make('category')
                    ->label(__('delivery_company.disputes.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('status')
                    ->label(__('delivery_company.disputes.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('created_at')
                    ->label(__('delivery_company.disputes.fields.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('delivery_company.disputes.fields.status'))
                    ->options(collect(DisputeStatus::cases())->mapWithKeys(
                        fn (DisputeStatus $status): array => [$status->value => $status->label()],
                    )->all()),
                SelectFilter::make('category')
                    ->label(__('delivery_company.disputes.fields.category'))
                    ->options(collect(DisputeCategory::cases())->mapWithKeys(
                        fn (DisputeCategory $category): array => [$category->value => $category->label()],
                    )->all()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('booking'))
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;

final class EventBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')->label(__('cleaning_admin.event_bookings.fields.booking_number'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('cleaning_admin.event_bookings.fields.status'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('event_type')->label(__('cleaning_admin.event_bookings.fields.event_type'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('customer.name')->label(__('cleaning_admin.event_bookings.fields.customer'))->searchable(),
                TextColumn::make('scheduled_date')->label(__('cleaning_admin.event_bookings.fields.scheduled_date'))->date()->sortable(),
                TextColumn::make('scheduled_time')->label(__('cleaning_admin.event_bookings.fields.scheduled_time')),
                TextColumn::make('total_price')->label(__('cleaning_admin.event_bookings.fields.total_price'))->money(config('app.currency', 'SYP'))->sortable(),
            ])
            ->filters([
                Filter::make('has_dispute')
                    ->label(__('cleaning_admin.event_bookings.filters.has_dispute'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                SelectFilter::make('status')
                    ->label(__('cleaning_admin.event_bookings.fields.status'))
                    ->options(collect(EventBookingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('event_type')
                    ->label(__('cleaning_admin.event_bookings.fields.event_type'))
                    ->options(collect(EventType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

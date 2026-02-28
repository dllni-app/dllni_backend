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
                TextColumn::make('booking_number')->label('رقم الحجز')->searchable()->sortable(),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('event_type')->label('نوع المناسبة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('customer.name')->label('العميل')->searchable(),
                TextColumn::make('scheduled_date')->label('التاريخ')->date()->sortable(),
                TextColumn::make('scheduled_time')->label('الوقت'),
                TextColumn::make('total_price')->label('المجموع')->money('SAR')->sortable(),
            ])
            ->filters([
                Filter::make('has_dispute')
                    ->label('يحتوي على نزاع')
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(collect(EventBookingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('event_type')
                    ->label('نوع المناسبة')
                    ->options(collect(EventType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

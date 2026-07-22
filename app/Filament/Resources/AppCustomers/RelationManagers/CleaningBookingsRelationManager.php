<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningBookingStatus;

final class CleaningBookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'cleaningBookings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'طلبات التنظيف';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof CleaningBookingStatus) {
                            return $state->label();
                        }

                        return CleaningBookingStatus::tryFrom((string) $state)?->label() ?? (string) $state;
                    }),
                TextColumn::make('scheduled_date')
                    ->label('التاريخ')
                    ->date()
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label('الوقت')
                    ->placeholder('—'),
                TextColumn::make('total_price')
                    ->label('السعر')
                    ->money('SYP')
                    ->placeholder('—'),
                TextColumn::make('neighborhood_name')
                    ->label('الحي')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('scheduled_date', 'desc');
    }
}

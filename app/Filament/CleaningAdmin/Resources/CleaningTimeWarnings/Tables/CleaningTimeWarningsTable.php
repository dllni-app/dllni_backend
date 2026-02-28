<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CleaningTimeWarningsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_type'),
                TextColumn::make('booking_id')->label('Booking #'),
                TextColumn::make('customer_response')->badge()->placeholder('-'),
                TextColumn::make('worker_response')->badge()->placeholder('-'),
                TextColumn::make('additional_minutes')->label('+ Minutes')->placeholder('-'),
                TextColumn::make('worker_reject_message')->limit(40)->placeholder('-'),
                TextColumn::make('sent_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_response')->options([
                    'extend_time' => 'Extend Time',
                    'commit_current_time' => 'Commit Current Time',
                    'finish_early' => 'Finish Early',
                ]),
                SelectFilter::make('worker_response')->options([
                    'extend_time' => 'Extend Time',
                    'commit_current_time' => 'Commit Current Time',
                    'finish_early' => 'Finish Early',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

<?php

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EventBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('event_type')->badge(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('scheduled_date')->date()->sortable(),
                TextColumn::make('scheduled_time'),
                TextColumn::make('total_price')->money('SAR')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'team_assigned' => 'Team Assigned',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('event_type')->options([
                    'wedding' => 'Wedding',
                    'party' => 'Party',
                    'conference' => 'Conference',
                    'other' => 'Other',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

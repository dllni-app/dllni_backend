<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CleaningBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('worker.first_name')->label('Worker')->placeholder('-'),
                TextColumn::make('scheduled_date')->date()->sortable(),
                TextColumn::make('scheduled_time'),
                TextColumn::make('total_price')->money('SAR')->sortable(),
                TextColumn::make('disputes_count')->counts('disputes')->label('Disputes'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'worker_assigned' => 'Worker Assigned',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),
                Filter::make('scheduled_today')
                    ->query(fn (Builder $query): Builder => $query->whereDate('scheduled_date', today())),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

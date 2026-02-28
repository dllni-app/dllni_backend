<?php

namespace App\Filament\CleaningAdmin\Resources\Disputes\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->searchable()->sortable(),
                TextColumn::make('booking_type')->label('Booking Type'),
                TextColumn::make('category')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('resolution')->placeholder('-'),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open',
                    'under_review' => 'Under Review',
                    'resolved' => 'Resolved',
                    'closed' => 'Closed',
                ]),
                SelectFilter::make('resolution')->options([
                    'partial_refund' => 'Partial Refund',
                    'worker_penalty' => 'Worker Penalty',
                    'rejected' => 'Rejected',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

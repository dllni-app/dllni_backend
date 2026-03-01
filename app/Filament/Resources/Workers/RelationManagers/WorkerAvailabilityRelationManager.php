<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\RelationManagers;

use App\Enums\AvailabilityType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class WorkerAvailabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'availability';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('cleaning_admin.workers.sections.availability');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('availability_date')
                    ->label(__('cleaning_admin.workers.availability_fields.date'))
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('availability_type')
                    ->label(__('cleaning_admin.workers.availability_fields.type'))
                    ->formatStateUsing(fn (?AvailabilityType $state) => $state?->label() ?? '-'),
                TextColumn::make('start_time')
                    ->label(__('cleaning_admin.workers.availability_fields.start'))
                    ->time('H:i')
                    ->placeholder('-'),
                TextColumn::make('end_time')
                    ->label(__('cleaning_admin.workers.availability_fields.end'))
                    ->time('H:i')
                    ->placeholder('-'),
            ])
            ->defaultSort('availability_date', 'desc');
    }
}

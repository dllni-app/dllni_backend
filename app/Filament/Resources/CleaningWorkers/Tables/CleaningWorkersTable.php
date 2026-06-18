<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CleaningWorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('first_name')->searchable(),
                TextColumn::make('user.name')->searchable(),
                TextColumn::make('user.phone'),
                TextColumn::make('trust_score')->sortable(),
                TextColumn::make('average_rating')->sortable(),
                TextColumn::make('total_completed_jobs')->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

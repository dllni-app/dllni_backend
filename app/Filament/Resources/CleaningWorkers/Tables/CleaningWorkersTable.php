<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Tables;

use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CleaningWorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone'),
                TextColumn::make('created_at')->since(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (User $record): string => CleaningWorkerResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (User $record): string => CleaningWorkerResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}

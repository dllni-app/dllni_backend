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
                TextColumn::make('id')->label(__('cleaning_admin.workers.fields.id'))->sortable(),
                TextColumn::make('first_name')->label(__('cleaning_admin.workers.fields.first_name'))->searchable(),
                TextColumn::make('user.name')->label(__('cleaning_admin.workers.fields.user_name'))->searchable(),
                TextColumn::make('user.phone')->label(__('cleaning_admin.workers.fields.phone')),
                TextColumn::make('trust_score')->label(__('cleaning_admin.workers.fields.trust_score'))->sortable(),
                TextColumn::make('average_rating')->label(__('cleaning_admin.workers.fields.average_rating'))->sortable(),
                TextColumn::make('total_completed_jobs')->label(__('cleaning_admin.workers.fields.total_completed_jobs'))->sortable(),
                IconColumn::make('is_active')->label(__('cleaning_admin.workers.fields.is_active'))->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

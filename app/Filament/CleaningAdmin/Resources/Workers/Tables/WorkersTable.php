<?php

namespace App\Filament\CleaningAdmin\Resources\Workers\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('first_name')->searchable(),
                TextColumn::make('user.phone')->label('Phone'),
                TextColumn::make('trust_score')->sortable(),
                TextColumn::make('average_rating')->sortable(),
                TextColumn::make('total_completed_jobs')->sortable(),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('is_suspended')->boolean(),
                IconColumn::make('is_verified')->boolean(),
                IconColumn::make('is_featured')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
                TernaryFilter::make('is_suspended'),
                TernaryFilter::make('is_verified'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

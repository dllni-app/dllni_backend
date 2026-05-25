<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CleaningServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('description')->limit(80)->toggleable(),
                TextColumn::make('price')->numeric(2)->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

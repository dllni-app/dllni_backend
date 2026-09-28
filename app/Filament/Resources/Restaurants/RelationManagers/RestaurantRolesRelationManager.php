<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class RestaurantRolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $title = 'أدوار المطعم';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('slug')->label('المعرّف')->searchable(),
                TextColumn::make('staff_count')
                    ->label('عدد الموظفين')
                    ->getStateUsing(fn (Model $record): int => $record->staff()->count()),
            ])
            ->headerActions([])
            ->recordActions([])
            ->defaultSort('name');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RestaurantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('city')->label('المدينة')->searchable(),
                TextColumn::make('district')->label('الحي')->searchable(),
                TextColumn::make('reputation_score')->label('نقاط الثقة')->sortable(),
                TextColumn::make('average_rating')->label('متوسط التقييم')->sortable(),
                IconColumn::make('is_active')->boolean()->label('نشط'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

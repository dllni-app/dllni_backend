<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ServiceAddonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('slug')->label('المعرّف')->searchable(),
                TextColumn::make('pricing_type')->label('نوع التسعير')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('price_value')->label('السعر')->money('SAR'),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

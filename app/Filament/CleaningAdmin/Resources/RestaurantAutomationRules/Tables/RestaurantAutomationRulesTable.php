<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RestaurantAutomationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('اسم القاعدة')->searchable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __('restaurant_admin.enums.automation_type.'.($state ?? 'suspend'))),
                IconColumn::make('is_active')->label('نشطة')->boolean(),
                TextColumn::make('updated_at')->label('آخر تحديث')->since(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

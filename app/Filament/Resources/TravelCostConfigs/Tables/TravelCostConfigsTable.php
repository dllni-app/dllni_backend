<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TravelCostConfigsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('max_km')->label('أقصى كم (كم)'),
                TextColumn::make('cost_per_km')->label('سعر الكيلومتر')->money(config('app.currency', 'SYP')),
                TextColumn::make('fixed_fee')->label('الحد الأدنى لرسوم التنقل')->money(config('app.currency', 'SYP')),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

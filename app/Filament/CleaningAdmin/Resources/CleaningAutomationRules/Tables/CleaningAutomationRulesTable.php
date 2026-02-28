<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CleaningAutomationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('type')->label('النوع')->badge(),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->since(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

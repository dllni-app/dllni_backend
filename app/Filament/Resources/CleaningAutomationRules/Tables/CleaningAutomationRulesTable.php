<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Tables;

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
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('trigger_type')->label('Trigger type')->badge(),
                TextColumn::make('min_hours')->label('Required hours')->numeric(2)->sortable(),
                TextColumn::make('period_months')->label('Period months')->sortable(),
                TextColumn::make('reward_type')->label('Reward type')->badge(),
                TextColumn::make('reward_value')->label('Reward value')->numeric(2)->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->label('Created at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

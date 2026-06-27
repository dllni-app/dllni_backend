<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CleaningAutomationRuleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Loyalty rule')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('name')->label('Name'),
                                TextEntry::make('trigger_type')->label('Trigger type')->badge(),
                                TextEntry::make('reward_type')->label('Reward type')->badge(),
                                TextEntry::make('reward_value')->label('Reward value')->numeric(2),
                                TextEntry::make('min_hours')->label('Minimum completed hours')->numeric(2),
                                TextEntry::make('period_months')->label('Period in months'),
                                IconEntry::make('is_active')->label('Active')->boolean(),
                                TextEntry::make('created_at')->label('Created at')->dateTime('Y-m-d H:i'),
                                TextEntry::make('updated_at')->label('Updated at')->dateTime('Y-m-d H:i'),
                            ]),
                    ]),
            ]);
    }
}

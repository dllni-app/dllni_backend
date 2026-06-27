<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Schemas;

use App\Models\CleaningAutomationRule;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CleaningAutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('type')->default(CleaningAutomationRule::TYPE_REWARD),
                Section::make('Loyalty rule')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('trigger_type')
                            ->label('Trigger type')
                            ->options([
                                CleaningAutomationRule::TRIGGER_TOTAL_HOURS => 'Total hours',
                            ])
                            ->default(CleaningAutomationRule::TRIGGER_TOTAL_HOURS)
                            ->required(),
                        Select::make('reward_type')
                            ->label('Reward type')
                            ->options([
                                CleaningAutomationRule::REWARD_FREE_HOURS => 'Free hours',
                            ])
                            ->default(CleaningAutomationRule::REWARD_FREE_HOURS)
                            ->required(),
                        TextInput::make('reward_value')
                            ->label('Reward value')
                            ->numeric()
                            ->minValue(0.01)
                            ->default(0)
                            ->required(),
                        TextInput::make('min_hours')
                            ->label('Minimum completed hours')
                            ->helperText('The member becomes eligible when completed cleaning hours reach this number within the selected period.')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),
                        TextInput::make('period_months')
                            ->label('Period in months')
                            ->helperText('Example: 2 means the system checks the last two months of completed bookings.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }
}

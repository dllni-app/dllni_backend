<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class CleaningAutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Name')->required(),
                Select::make('type')
                    ->label('Type')
                    ->options([
                        'suspend' => 'Suspend',
                        'reward' => 'Reward',
                    ])
                    ->required(),
                Toggle::make('is_active')->label('Active')->default(true),
                KeyValue::make('conditions')
                    ->label('Conditions')
                    ->keyLabel('Key')
                    ->valueLabel('Value'),
                KeyValue::make('actions')
                    ->label('Actions')
                    ->keyLabel('Key')
                    ->valueLabel('Value'),
            ]);
    }
}

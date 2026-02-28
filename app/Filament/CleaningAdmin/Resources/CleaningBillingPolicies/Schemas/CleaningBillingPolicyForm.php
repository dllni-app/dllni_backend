<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CleaningBillingPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                Select::make('billing_mode')
                    ->options([
                        'fixed' => 'Fixed',
                        'hourly' => 'Hourly',
                        'sqm' => 'Per SQM',
                    ])
                    ->required(),
                KeyValue::make('rules'),
                Toggle::make('is_active')->default(true),
                Toggle::make('is_default')->default(false),
            ]);
    }
}

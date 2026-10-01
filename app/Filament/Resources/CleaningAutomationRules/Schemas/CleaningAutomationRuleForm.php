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
                TextInput::make('name')->label(__('cleaning_admin.automation.fields.name'))->required(),
                Select::make('type')
                    ->label(__('cleaning_admin.automation.fields.type'))
                    ->options([
                        'suspend' => __('cleaning_admin.automation.types.suspend'),
                        'reward' => __('cleaning_admin.automation.types.reward'),
                    ])
                    ->required(),
                Toggle::make('is_active')->label(__('cleaning_admin.automation.fields.is_active'))->default(true),
                KeyValue::make('conditions')
                    ->label(__('cleaning_admin.automation.fields.conditions'))
                    ->keyLabel(__('cleaning_admin.automation.fields.key'))
                    ->valueLabel(__('cleaning_admin.automation.fields.value')),
                KeyValue::make('actions')
                    ->label(__('cleaning_admin.automation.fields.actions'))
                    ->keyLabel(__('cleaning_admin.automation.fields.key'))
                    ->valueLabel(__('cleaning_admin.automation.fields.value')),
            ]);
    }
}

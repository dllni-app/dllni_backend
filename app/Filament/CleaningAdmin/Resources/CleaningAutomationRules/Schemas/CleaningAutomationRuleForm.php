<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Schemas;

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
                TextInput::make('name')->label('الاسم')->required(),
                Select::make('type')
                    ->label('النوع')
                    ->options([
                        'suspend' => 'تعليق تلقائي',
                        'reward' => 'مكافأة',
                    ])
                    ->required(),
                Toggle::make('is_active')->label('نشط')->default(true),
                KeyValue::make('conditions')
                    ->label('الشروط')
                    ->keyLabel('المفتاح')
                    ->valueLabel('القيمة'),
                KeyValue::make('actions')
                    ->label('الإجراءات')
                    ->keyLabel('المفتاح')
                    ->valueLabel('القيمة'),
            ]);
    }
}

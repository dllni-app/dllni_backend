<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class RestaurantAutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('اسم القاعدة')->required(),
                Select::make('type')
                    ->label('النوع')
                    ->options([
                        'suspend' => __('restaurant_admin.enums.automation_type.suspend'),
                        'reward' => __('restaurant_admin.enums.automation_type.reward'),
                    ])
                    ->required(),
                Toggle::make('is_active')->label('نشطة')->default(true),
                KeyValue::make('conditions')->label('الشروط'),
                KeyValue::make('actions')->label('الإجراءات'),
            ]);
    }
}

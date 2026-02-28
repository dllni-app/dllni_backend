<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningBillingMode;

final class CleaningBillingPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required(),
                Select::make('billing_mode')
                    ->label('طريقة الفوترة')
                    ->options(collect(CleaningBillingMode::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                TextInput::make('min_billable_minutes')
                    ->label('الحد الأدنى للدقائق القابلة للفوترة')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('مثال: 120')
                    ->visible(fn ($get) => $get('billing_mode') === CleaningBillingMode::ActualWorkingTime->value),
                Toggle::make('is_active')->label('نشط')->default(true),
                Toggle::make('is_default')->label('افتراضي')->default(false),
            ]);
    }
}

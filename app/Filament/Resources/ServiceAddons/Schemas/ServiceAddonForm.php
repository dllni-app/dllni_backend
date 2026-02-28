<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class ServiceAddonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Select::make('pricing_type')
                    ->options([
                        'fixed' => 'Fixed',
                        'per_hour' => 'Per Hour',
                        'per_sqm' => 'Per SQM',
                    ])
                    ->required(),
                TextInput::make('price_value')->numeric()->required(),
                Toggle::make('is_active')->default(true),
            ]);
    }
}

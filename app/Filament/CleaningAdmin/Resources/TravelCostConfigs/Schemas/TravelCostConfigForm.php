<?php

namespace App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TravelCostConfigForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('max_km')->numeric()->required(),
                TextInput::make('cost_per_km')->numeric()->required(),
                TextInput::make('fixed_fee')->numeric()->required(),
                Toggle::make('is_active')->default(true),
            ]);
    }
}

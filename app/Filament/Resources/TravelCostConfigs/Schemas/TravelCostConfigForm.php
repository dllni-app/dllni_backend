<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class TravelCostConfigForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('الاسم')->required(),
                TextInput::make('max_km')->label('أقصى كم (كم)')->numeric()->required(),
                TextInput::make('cost_per_km')->label('سعر الكيلومتر')->numeric()->required(),
                TextInput::make('fixed_fee')->label('الحد الأدنى لرسوم التنقل')->numeric()->required(),
                Toggle::make('is_active')->label('نشط')->default(true),
            ]);
    }
}

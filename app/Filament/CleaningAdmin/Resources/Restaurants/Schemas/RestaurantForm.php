<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class RestaurantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('اسم المطعم')->required()->maxLength(255),
                TextInput::make('city')->label('المدينة')->maxLength(255),
                TextInput::make('district')->label('الحي')->maxLength(255),
                TextInput::make('reputation_score')->label('نقاط الثقة')->numeric()->required(),
                Toggle::make('is_active')->label('نشط')->default(true),
                Toggle::make('is_featured')->label('مميز')->default(false),
                Toggle::make('is_temporarily_closed')->label('مغلق مؤقتاً')->default(false),
            ]);
    }
}

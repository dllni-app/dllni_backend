<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\Schemas;

use Filament\Forms\Components\DateTimePicker;
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
                TextInput::make('reputation_score')->label('نقاط الثقة')->numeric()->required()->minValue(0)->maxValue(100),
                TextInput::make('warning_count')->label('عدد التحذيرات')->numeric()->minValue(0)->default(0),
                TextInput::make('visibility_score')->label('درجة الظهور')->numeric()->minValue(0)->maxValue(100),
                Toggle::make('is_active')->label('نشط')->default(true),
                Toggle::make('is_featured')->label('مميز')->default(false),
                Toggle::make('is_temporarily_closed')->label('مغلق مؤقتاً')->default(false),
                DateTimePicker::make('suspension_until')->label('تعليق حتى')->nullable(),
            ]);
    }
}

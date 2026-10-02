<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class RestaurantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('البيانات الأساسية')
                ->description('الحقول التشغيلية الحساسة مثل الثقة والتعليق والتفعيل تتم من إجراءات الإدارة الموثقة في صفحة العرض.')
                ->schema([
                    TextInput::make('name')->label('اسم المطعم')->required()->maxLength(255),
                    TextInput::make('city')->label('المدينة')->maxLength(255),
                    TextInput::make('district')->label('الحي')->maxLength(255),
                ])->columns(3),
        ]);
    }
}

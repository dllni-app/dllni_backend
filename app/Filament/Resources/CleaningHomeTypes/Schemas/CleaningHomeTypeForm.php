<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Models\CleaningHomeType;

final class CleaningHomeTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات النوع')
                    ->description('هذه البيانات تظهر مباشرة في قائمة التنظيفات أو المناسبات داخل تطبيق المستخدم.')
                    ->columns(2)
                    ->schema([
                        Select::make('section')
                            ->label('القسم')
                            ->options([
                                CleaningHomeType::SECTION_PROPERTY => 'التنظيفات / أنواع العقارات',
                                CleaningHomeType::SECTION_OCCASION => 'المناسبات',
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('title')
                            ->label('الاسم الظاهر للمستخدم')
                            ->required()
                            ->maxLength(255)
                            ->helperText('يولّد النظام رمز النوع تلقائياً عند الحفظ.'),
                    ]),
                Section::make('الصورة والحالة')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('رفع صورة')
                            ->disk('public')
                            ->directory('cleaning-home-types')
                            ->image()
                            ->imageEditor()
                            ->maxSize(5120)
                            ->required()
                            ->helperText('ارفع صورة واضحة لا يتجاوز حجمها 5 ميغابايت.'),
                        Toggle::make('is_active')
                            ->label('ظاهر في التطبيق')
                            ->default(true)
                            ->helperText('عند تعطيله يختفي النوع من التطبيق مع الاحتفاظ بالطلبات السابقة.'),
                    ]),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
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
                            ->native(false)
                            ->live(),
                        TextInput::make('title')
                            ->label('الاسم الظاهر للمستخدم')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('رمز النوع')
                            ->helperText('قيمة ثابتة ترسلها التطبيقات إلى واجهات API. استخدم أحرفاً إنجليزية صغيرة وأرقاماً وشرطة سفلية فقط، ولا تغيّرها بعد استخدام النوع في الطلبات.')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_]+$/')
                            ->unique(
                                table: 'cleaning_home_types',
                                column: 'code',
                                ignorable: null,
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                                    return $rule->where('section', (string) $get('section'));
                                },
                            ),
                        TextInput::make('sort_order')
                            ->label('ترتيب العرض')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
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
                            ->live()
                            ->required(fn (Get $get): bool => blank($get('external_image_url')))
                            ->helperText('الصورة المرفوعة لها الأولوية على رابط الصورة الخارجي.'),
                        TextInput::make('external_image_url')
                            ->label('رابط صورة خارجي')
                            ->url()
                            ->maxLength(2048)
                            ->live()
                            ->required(fn (Get $get): bool => blank($get('image_path')))
                            ->helperText('استخدمه فقط عند عدم رفع صورة من لوحة التحكم.'),
                        Toggle::make('is_active')
                            ->label('ظاهر في التطبيق')
                            ->default(true)
                            ->helperText('عند تعطيله يختفي النوع من التطبيق مع الاحتفاظ بالطلبات السابقة.'),
                    ]),
            ]);
    }
}

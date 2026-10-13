<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->live()
                            ->native(false),
                        Select::make('booking_value')
                            ->label('نوع الحجز')
                            ->helperText('اختر مسار حجز مدعوماً؛ يمكن تغيير الاسم والوصف والأيقونة دون تغيير قواعد الحجز.')
                            ->options(fn (Get $get): array => $get('section') === CleaningHomeType::SECTION_OCCASION
                                ? [
                                    'family_dinner' => 'عشاء عائلي',
                                    'birthday' => 'عيد ميلاد',
                                    'large_gathering' => 'تجمع كبير',
                                    'funeral' => 'عزاء',
                                ]
                                : [
                                    'villa' => 'فيلا',
                                    'office' => 'مكتب',
                                    'apartment' => 'شقة',
                                    'studio' => 'استديو',
                                    'house' => 'منزل',
                                ])
                            ->required()
                            ->live()
                            ->native(false),
                        TextInput::make('title')
                            ->label('الاسم الظاهر للمستخدم')
                            ->required()
                            ->maxLength(255)
                            ->helperText('يولّد النظام رمز النوع تلقائياً عند الحفظ.'),
                        Select::make('icon_key')
                            ->label('أيقونة النوع')
                            ->options([
                                'villa' => 'فيلا',
                                'office' => 'مكتب',
                                'apartment' => 'شقة',
                                'studio' => 'استديو',
                                'family_dinner' => 'عشاء عائلي',
                                'birthday' => 'عيد ميلاد',
                                'large_gathering' => 'تجمع كبير',
                                'funeral' => 'عزاء',
                            ])
                            ->native(false),
                        Textarea::make('description')
                            ->label('الوصف الظاهر للمستخدم')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
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
                            ->nullable()
                            ->helperText('ارفع صورة واضحة لا يتجاوز حجمها 5 ميغابايت.'),
                        Toggle::make('is_active')
                            ->label('ظاهر في التطبيق')
                            ->default(true)
                            ->helperText('عند تعطيله يختفي النوع من التطبيق مع الاحتفاظ بالطلبات السابقة.'),
                    ]),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class CleaningBannerForm
{
    private const IMAGE_MIN_WIDTH = 1200;

    private const IMAGE_MIN_HEIGHT = 520;

    private const IMAGE_MAX_SIZE_KB = 1024;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * @return array<int, mixed>
     */
    public static function components(): array
    {
        return [
            Section::make(__('cleaning_admin.cleaning_banners.sections.content'))
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label(__('cleaning_admin.cleaning_banners.fields.title'))
                        ->maxLength(255),
                    Textarea::make('subtitle')
                        ->label(__('cleaning_admin.cleaning_banners.fields.subtitle'))
                        ->maxLength(1000),
                ]),
            Section::make(__('cleaning_admin.cleaning_banners.sections.visibility'))
                ->columns(3)
                ->schema([
                    Toggle::make('is_active')
                        ->label(__('cleaning_admin.cleaning_banners.fields.is_active'))
                        ->default(true),
                    DateTimePicker::make('starts_at')
                        ->label(__('cleaning_admin.cleaning_banners.fields.starts_at')),
                    DateTimePicker::make('ends_at')
                        ->label(__('cleaning_admin.cleaning_banners.fields.ends_at'))
                        ->after('starts_at'),
                ]),
            Section::make(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                ->description(app()->isLocale('ar')
                    ? 'بانر قسم التنظيف يظهر في تطبيق المستخدم كصورة فقط.'
                    : 'The cleaning banner is displayed in the user app as an image only.')
                ->schema([
                    FileUpload::make('image_path')
                        ->label(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                        ->helperText(app()->isLocale('ar')
                            ? 'الأبعاد الموصى بها: 1200×520 بكسل. الحد الأقصى لحجم الصورة: 1 ميغابايت. الصيغ المدعومة: JPG وPNG وWebP.'
                            : 'Recommended dimensions: 1200×520 px. Maximum image size: 1 MB. Supported formats: JPG, PNG, and WebP.')
                        ->disk('public')
                        ->directory('cleaning-banners')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->rule(
                            Rule::dimensions()
                                ->minWidth(self::IMAGE_MIN_WIDTH)
                                ->minHeight(self::IMAGE_MIN_HEIGHT)
                        )
                        ->maxSize(self::IMAGE_MAX_SIZE_KB)
                        ->validationMessages([
                            'dimensions' => app()->isLocale('ar')
                                ? 'يجب ألا تقل أبعاد صورة البانر عن 1200×520 بكسل.'
                                : 'The banner image dimensions must be at least 1200×520 px.',
                            'max' => app()->isLocale('ar')
                                ? 'يجب ألا يتجاوز حجم صورة البانر 1 ميغابايت.'
                                : 'The banner image must not exceed 1 MB.',
                            'mimetypes' => app()->isLocale('ar')
                                ? 'صيغة صورة البانر غير مدعومة. استخدم JPG أو PNG أو WebP.'
                                : 'Unsupported banner image format. Use JPG, PNG, or WebP.',
                        ])
                        ->required(),
                ]),
        ];
    }
}

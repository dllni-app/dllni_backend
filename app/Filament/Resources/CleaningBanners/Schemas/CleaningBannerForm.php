<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class CleaningBannerForm
{
    private const IMAGE_MIN_WIDTH = 1200;

    private const IMAGE_MIN_HEIGHT = 520;

    private const IMAGE_MAX_SIZE_KB = 4096;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cleaning_admin.cleaning_banners.sections.content'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('cleaning_admin.cleaning_banners.fields.title'))
                            ->required()
                            ->maxLength(255),
                        Textarea::make('subtitle')
                            ->label(__('cleaning_admin.cleaning_banners.fields.subtitle'))
                            ->columnSpanFull()
                            ->rows(3),
                        FileUpload::make('image_path')
                            ->label(__('cleaning_admin.cleaning_banners.fields.image'))
                            ->helperText(app()->isLocale('ar')
                                ? 'المقاس الأدنى 1200×520 بكسل. الحد الأقصى لحجم الملف 4 ميغابايت. الصيغ المدعومة: JPG وPNG وWebP.'
                                : 'Minimum dimensions are 1200×520 px. Maximum file size is 4 MB. Supported formats: JPG, PNG, and WebP.')
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
                                    ? 'يجب ألا يتجاوز حجم صورة البانر 4 ميغابايت.'
                                    : 'The banner image must not exceed 4 MB.',
                                'mimetypes' => app()->isLocale('ar')
                                    ? 'صيغة صورة البانر غير مدعومة. استخدم JPG أو PNG أو WebP.'
                                    : 'Unsupported banner image format. Use JPG, PNG, or WebP.',
                            ])
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('cleaning_admin.cleaning_banners.sections.visibility'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')
                            ->label(__('cleaning_admin.cleaning_banners.fields.sort_order'))
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('cleaning_admin.cleaning_banners.fields.is_active'))
                            ->default(true),
                        DateTimePicker::make('starts_at')
                            ->label(__('cleaning_admin.cleaning_banners.fields.starts_at')),
                        DateTimePicker::make('ends_at')
                            ->label(__('cleaning_admin.cleaning_banners.fields.ends_at')),
                    ]),
            ]);
    }
}

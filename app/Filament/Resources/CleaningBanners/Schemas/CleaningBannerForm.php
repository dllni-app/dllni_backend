<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Schemas;

use Filament\Forms\Components\FileUpload;
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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class HomepageBannerForm
{
    private const RECOMMENDED_WIDTH = 1200;

    private const RECOMMENDED_HEIGHT = 1000;

    private const MIN_WIDTH = 600;

    private const MIN_HEIGHT = 500;

    private const MAX_SIZE_KB = 1024;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                    ->description(app()->isLocale('ar')
                        ? 'بانر الصفحة الرئيسية يظهر في تطبيق المستخدم كصورة فقط.'
                        : 'The homepage banner is displayed in the user app as an image only.')
                    ->schema([
                        FileUpload::make('image_upload')
                            ->label(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                            ->helperText(app()->isLocale('ar')
                                ? 'الأبعاد الموصى بها: 1200×1000 بكسل بنسبة 6:5. الحد الأدنى: 600×500 بكسل. الحد الأقصى للحجم: 1 ميغابايت. الصيغ المدعومة: JPG وPNG وWebP. عند التعديل اترك الحقل فارغاً للاحتفاظ بالصورة الحالية.'
                                : 'Recommended dimensions: 1200×1000 px at a 6:5 ratio. Minimum: 600×500 px. Maximum file size: 1 MB. Supported formats: JPG, PNG, and WebP. Leave empty while editing to keep the current image.')
                            ->image()
                            ->imageEditor()
                            ->imageEditorViewportWidth(self::RECOMMENDED_WIDTH)
                            ->imageEditorViewportHeight(self::RECOMMENDED_HEIGHT)
                            ->imageAspectRatio('6:5')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rule(
                                Rule::dimensions()
                                    ->minWidth(self::MIN_WIDTH)
                                    ->minHeight(self::MIN_HEIGHT)
                                    ->ratio(6 / 5)
                            )
                            ->maxSize(self::MAX_SIZE_KB)
                            ->validationMessages([
                                'dimensions' => app()->isLocale('ar')
                                    ? 'يجب أن تكون صورة البانر بنسبة 6:5 وبأبعاد لا تقل عن 600×500 بكسل. المقاس الموصى به 1200×1000 بكسل.'
                                    : 'The banner image must use a 6:5 ratio and be at least 600×500 px. The recommended size is 1200×1000 px.',
                                'max' => app()->isLocale('ar')
                                    ? 'يجب ألا يتجاوز حجم صورة البانر 1 ميغابايت.'
                                    : 'The banner image must not exceed 1 MB.',
                                'mimetypes' => app()->isLocale('ar')
                                    ? 'صيغة صورة البانر غير مدعومة. استخدم JPG أو PNG أو WebP.'
                                    : 'Unsupported banner image format. Use JPG, PNG, or WebP.',
                            ])
                            ->maxFiles(1)
                            ->storeFiles(false)
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create'),
                    ]),
            ]);
    }
}

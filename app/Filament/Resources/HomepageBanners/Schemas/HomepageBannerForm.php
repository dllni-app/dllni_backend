<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\User\Enums\MarketingOfferTheme;

final class HomepageBannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(app()->isLocale('ar') ? 'محتوى البانر' : 'Banner Content')
                    ->description(app()->isLocale('ar')
                        ? 'هذه البيانات تظهر في بانر الصفحة الرئيسية لتطبيق المستخدم.'
                        : 'This content is displayed in the user app homepage banner.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(app()->isLocale('ar') ? 'العنوان' : 'Title')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('discount_label')
                            ->label(app()->isLocale('ar') ? 'نص العرض أو الخصم' : 'Offer or Discount Label')
                            ->helperText(app()->isLocale('ar')
                                ? 'مثال: خصم 15%.'
                                : 'Example: 15% off.')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label(app()->isLocale('ar') ? 'الوصف' : 'Description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        TextInput::make('promo_code')
                            ->label(app()->isLocale('ar') ? 'رمز العرض' : 'Promo Code')
                            ->maxLength(255),
                        Select::make('theme')
                            ->label(app()->isLocale('ar') ? 'لون العرض' : 'Theme')
                            ->options([
                                MarketingOfferTheme::Orange->value => app()->isLocale('ar') ? 'برتقالي' : 'Orange',
                                MarketingOfferTheme::Green->value => app()->isLocale('ar') ? 'أخضر' : 'Green',
                                MarketingOfferTheme::Gold->value => app()->isLocale('ar') ? 'ذهبي' : 'Gold',
                            ])
                            ->default(MarketingOfferTheme::Orange->value)
                            ->native(false)
                            ->required(),
                        FileUpload::make('image_upload')
                            ->label(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                            ->helperText(app()->isLocale('ar')
                                ? 'الحد الأقصى 4 ميغابايت. عند التعديل اترك الحقل فارغاً للاحتفاظ بالصورة الحالية.'
                                : 'Maximum 4 MB. Leave empty while editing to keep the current image.')
                            ->image()
                            ->imageEditor()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(4096)
                            ->storeFiles(false)
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),
                    ]),
                Section::make(app()->isLocale('ar') ? 'الظهور والترتيب' : 'Visibility and Ordering')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')
                            ->label(app()->isLocale('ar') ? 'الترتيب' : 'Sort Order')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(app()->isLocale('ar') ? 'نشط' : 'Active')
                            ->default(true),
                        DateTimePicker::make('starts_at')
                            ->label(app()->isLocale('ar') ? 'يبدأ الظهور في' : 'Starts At'),
                        DateTimePicker::make('ends_at')
                            ->label(app()->isLocale('ar') ? 'ينتهي الظهور في' : 'Ends At')
                            ->afterOrEqual('starts_at')
                            ->validationMessages([
                                'after_or_equal' => app()->isLocale('ar')
                                    ? 'يجب أن يكون وقت نهاية الظهور بعد وقت البداية أو مساوياً له.'
                                    : 'The end time must be after or equal to the start time.',
                            ]),
                    ]),
            ]);
    }
}

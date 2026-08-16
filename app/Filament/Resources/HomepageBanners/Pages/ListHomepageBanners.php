<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use App\Filament\Resources\HomepageBanners\Schemas\HomepageBannerForm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

final class ListHomepageBanners extends ListRecords
{
    protected static string $resource = HomepageBannerResource::class;

    public function getTitle(): string|Htmlable
    {
        return app()->isLocale('ar') ? 'بنرات الصفحة الرئيسية' : 'Homepage Banners';
    }

    public function getSubheading(): ?string
    {
        return app()->isLocale('ar')
            ? 'أضف البنرات، احذفها، أو غيّر ترتيب ظهورها في التطبيق بالسحب والإفلات.'
            : 'Add banners, delete them, or drag and drop to change their display order in the app.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(app()->isLocale('ar') ? 'إضافة بانر' : 'Add Banner')
                ->modalHeading(app()->isLocale('ar') ? 'إضافة بانر' : 'Add Banner')
                ->modalSubmitActionLabel(app()->isLocale('ar') ? 'إضافة' : 'Add')
                ->schema(HomepageBannerForm::components())
                ->createAnother(false)
                ->using(function (array $data): MarketingOffer {
                    return DB::transaction(function () use ($data): MarketingOffer {
                        $image = Arr::pull($data, 'image_upload');
                        $nextSortOrder = ((int) (MarketingOffer::query()->max('sort_order') ?? -1)) + 1;

                        $record = MarketingOffer::query()->create(array_merge($data, [
                            'title' => '',
                            'description' => null,
                            'discount_label' => '',
                            'promo_code' => null,
                            'starts_at' => null,
                            'ends_at' => null,
                            'theme' => MarketingOfferTheme::Orange->value,
                            'sort_order' => $nextSortOrder,
                            'is_active' => true,
                        ]));

                        self::attachBannerImage($record, $image);

                        return $record;
                    });
                })
                ->successNotificationTitle(app()->isLocale('ar')
                    ? 'تمت إضافة البانر بنجاح'
                    : 'Banner added successfully'),
        ];
    }

    private static function attachBannerImage(MarketingOffer $record, mixed $image): void
    {
        if (is_array($image)) {
            $image = Arr::first($image);
        }

        if ($image instanceof UploadedFile) {
            $record
                ->addMedia($image)
                ->toMediaCollection(MarketingOffer::IMAGE_COLLECTION);

            return;
        }

        if (is_object($image) && method_exists($image, 'getRealPath')) {
            $realPath = $image->getRealPath();

            if (! is_string($realPath) || $realPath === '') {
                return;
            }

            $mediaAdder = $record->addMedia($realPath);

            if (method_exists($image, 'getClientOriginalName')) {
                $originalName = $image->getClientOriginalName();

                if (is_string($originalName) && $originalName !== '') {
                    $mediaAdder->usingFileName($originalName);
                }
            }

            $mediaAdder->toMediaCollection(MarketingOffer::IMAGE_COLLECTION);

            return;
        }

        if (is_string($image) && $image !== '') {
            $record
                ->addMediaFromDisk($image, 'public')
                ->toMediaCollection(MarketingOffer::IMAGE_COLLECTION);
        }
    }
}

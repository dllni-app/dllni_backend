<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use App\Filament\Resources\HomepageBanners\Pages\Concerns\SyncsHomepageBannerImage;
use Filament\Resources\Pages\CreateRecord;
use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

final class CreateHomepageBanner extends CreateRecord
{
    use SyncsHomepageBannerImage;

    protected static string $resource = HomepageBannerResource::class;

    /**
     * The homepage banner is image-only in the user app. Legacy marketing-offer
     * columns are still required by the existing table, so keep them internal
     * and populate safe defaults instead of exposing them in the Filament form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $nextSortOrder = ((int) (MarketingOffer::query()->max('sort_order') ?? -1)) + 1;

        return array_merge($data, [
            'title' => '',
            'description' => null,
            'discount_label' => '',
            'promo_code' => null,
            'starts_at' => null,
            'ends_at' => null,
            'theme' => MarketingOfferTheme::Orange->value,
            'sort_order' => $nextSortOrder,
            'is_active' => true,
        ]);
    }

    protected function afterCreate(): void
    {
        $this->syncHomepageBannerImageFromForm();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return app()->isLocale('ar')
            ? 'تم إنشاء بانر الصفحة الرئيسية'
            : 'Homepage banner created';
    }
}

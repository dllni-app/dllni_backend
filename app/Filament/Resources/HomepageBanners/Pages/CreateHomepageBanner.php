<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use App\Filament\Resources\HomepageBanners\Pages\Concerns\SyncsHomepageBannerImage;
use Filament\Resources\Pages\CreateRecord;

final class CreateHomepageBanner extends CreateRecord
{
    use SyncsHomepageBannerImage;

    protected static string $resource = HomepageBannerResource::class;

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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use App\Filament\Resources\HomepageBanners\Pages\Concerns\SyncsHomepageBannerImage;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditHomepageBanner extends EditRecord
{
    use SyncsHomepageBannerImage;

    protected static string $resource = HomepageBannerResource::class;

    protected function afterSave(): void
    {
        $this->syncHomepageBannerImageFromForm();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return app()->isLocale('ar')
            ? 'تم تحديث بانر الصفحة الرئيسية'
            : 'Homepage banner updated';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

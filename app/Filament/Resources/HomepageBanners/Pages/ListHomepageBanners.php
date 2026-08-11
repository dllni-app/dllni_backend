<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

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
            ? 'إدارة وترتيب البنرات والعروض التي تظهر أعلى الصفحة الرئيسية لتطبيق المستخدم.'
            : 'Manage and order the banners and offers displayed at the top of the user app homepage.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(app()->isLocale('ar') ? 'إضافة بانر' : 'Add Banner'),
        ];
    }
}

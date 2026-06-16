<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Pages;

use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use App\Filament\Resources\CleaningBanners\Widgets\CleaningBannerStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListCleaningBanners extends ListRecords
{
    protected static string $resource = CleaningBannerResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.cleaning_banners.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.cleaning_banners.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CleaningBannerStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cleaning_admin.cleaning_banners.actions.create')),
        ];
    }
}

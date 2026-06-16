<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannerStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $now = now();

        return [
            Stat::make(__('cleaning_admin.cleaning_banners.stats.total'), CleaningBanner::query()->count())
                ->icon('heroicon-o-photo')
                ->color('primary'),
            Stat::make(__('cleaning_admin.cleaning_banners.stats.active'), CleaningBanner::query()->where('is_active', true)->count())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make(__('cleaning_admin.cleaning_banners.stats.visible'), CleaningBanner::query()
                ->where('is_active', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                })
                ->count())
                ->icon('heroicon-o-eye')
                ->color('info'),
            Stat::make(__('cleaning_admin.cleaning_banners.stats.scheduled'), CleaningBanner::query()
                ->where('is_active', true)
                ->whereNotNull('starts_at')
                ->where('starts_at', '>', $now)
                ->count())
                ->icon('heroicon-o-calendar-days')
                ->color('warning'),
        ];
    }
}

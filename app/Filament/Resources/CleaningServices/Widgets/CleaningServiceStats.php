<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Models\CleaningService;

final class CleaningServiceStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            Stat::make(__('cleaning_admin.cleaning_services.stats.total'), CleaningService::query()->count())
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('primary'),
            Stat::make(__('cleaning_admin.cleaning_services.stats.active'), CleaningService::query()->where('is_active', true)->count())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}

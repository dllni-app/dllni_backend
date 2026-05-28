<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Widgets;

use App\Models\Worker;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class WorkerStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            Stat::make(__('cleaning_admin.workers.stats.total'), Worker::query()->count())
                ->icon('heroicon-o-user-group')
                ->color('primary'),
            Stat::make(__('cleaning_admin.workers.stats.active'), Worker::query()->where('is_active', true)->count())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make(__('cleaning_admin.workers.stats.suspended'), Worker::query()->where('is_suspended', true)->count())
                ->icon('heroicon-o-no-symbol')
                ->color('danger'),
            Stat::make(__('cleaning_admin.workers.stats.average_rating'), number_format((float) Worker::query()->avg('average_rating'), 1))
                ->icon('heroicon-o-star')
                ->color('warning'),
        ];
    }
}

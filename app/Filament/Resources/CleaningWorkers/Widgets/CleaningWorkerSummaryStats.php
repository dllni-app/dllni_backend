<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Widgets;

use App\Enums\UserModuleType;
use App\Models\Worker;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class CleaningWorkerSummaryStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $base = Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query
                ->where('module_type', UserModuleType::CleaningWorker));

        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->where('is_suspended', false)->count();
        $suspended = (clone $base)->where('is_suspended', true)->count();

        return [
            Stat::make('إجمالي العاملين', $total)
                ->description('جميع العاملين المسجلين في قسم التنظيف')
                ->icon('heroicon-o-users')
                ->color('primary'),
            Stat::make('الحسابات النشطة', $active)
                ->description('عاملون نشطون وغير موقوفين من الإدارة')
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make('العاملون الموقوفون', $suspended)
                ->description($suspended > 0 ? 'يحتاجون مراجعة قبل إعادة التفعيل' : 'لا يوجد عاملون موقوفون حالياً')
                ->icon('heroicon-o-no-symbol')
                ->color($suspended > 0 ? 'danger' : 'success'),
        ];
    }
}

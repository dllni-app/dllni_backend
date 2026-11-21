<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkerComplianceCases\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

final class WorkerComplianceOverviewStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        return auth()->user()?->can('cleaning.worker_compliance.overview.view') ?? false;
    }

    protected function getStats(): array
    {
        $now = now();
        $states = DB::table('worker_compliance_states')->where('is_enrolled', true);

        return [
            Stat::make(__('worker_compliance.overview.enrolled'), (clone $states)->count())
                ->icon('heroicon-o-user-group')
                ->color('primary'),
            Stat::make(__('worker_compliance.overview.recent_contact'), (clone $states)->where('last_contact_at', '>=', $now->copy()->subMinutes(15))->count())
                ->icon('heroicon-o-signal')
                ->color('success'),
            Stat::make(__('worker_compliance.overview.stale'), (clone $states)->whereNotNull('last_contact_at')->where('last_contact_at', '<', $now->copy()->subMinutes(15))->count())
                ->description(__('worker_compliance.overview.stale_description'))
                ->icon('heroicon-o-clock')
                ->color('warning'),
            Stat::make(__('worker_compliance.overview.unknown'), (clone $states)->whereNull('last_contact_at')->count())
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray'),
            Stat::make(__('worker_compliance.overview.unresolved'), DB::table('worker_compliance_review_cases')->whereIn('status', ['new', 'under_review'])->count())
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
        ];
    }
}

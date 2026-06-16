<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Widgets;

use App\Enums\AlertSeverity;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class SystemAlertStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            Stat::make(__('cleaning_admin.system_alerts.stats.total'), SystemAlert::query()->count())
                ->icon('heroicon-o-bell-alert')
                ->color('primary'),
            Stat::make(__('cleaning_admin.system_alerts.stats.new'), SystemAlert::query()->where('status', SystemAlertStatus::New->value)->count())
                ->icon('heroicon-o-sparkles')
                ->color('warning'),
            Stat::make(__('cleaning_admin.system_alerts.stats.critical'), SystemAlert::query()->where('severity', AlertSeverity::Critical->value)->count())
                ->icon('heroicon-o-fire')
                ->color('danger'),
            Stat::make(__('cleaning_admin.system_alerts.stats.resolved'), SystemAlert::query()->where('status', SystemAlertStatus::Resolved->value)->count())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}

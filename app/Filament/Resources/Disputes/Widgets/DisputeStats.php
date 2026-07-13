<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Widgets;

use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class DisputeStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            Stat::make(__('cleaning_admin.disputes.stats.total'), DisputeResource::getEloquentQuery()->count())
                ->icon('heroicon-o-exclamation-triangle')
                ->color('primary'),
            Stat::make(__('cleaning_admin.disputes.stats.open'), $this->statusCount(DisputeStatus::Open))
                ->icon('heroicon-o-clock')
                ->color('danger'),
            Stat::make(__('cleaning_admin.disputes.stats.under_review'), $this->statusCount(DisputeStatus::UnderReview))
                ->icon('heroicon-o-eye')
                ->color('warning'),
            Stat::make(__('cleaning_admin.disputes.stats.resolved'), $this->statusCount(DisputeStatus::Resolved) + $this->statusCount(DisputeStatus::Closed))
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }

    private function statusCount(DisputeStatus $status): int
    {
        return DisputeResource::getEloquentQuery()->where('status', $status->value)->count();
    }
}

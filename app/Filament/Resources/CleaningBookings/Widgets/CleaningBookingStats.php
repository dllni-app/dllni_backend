<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Widgets;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            Stat::make(__('cleaning_admin.booking.stats.total'), CleaningBooking::query()->count())
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->url(CleaningBookingResource::getUrl('index'))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(__('cleaning_admin.booking.stats.pending'), $this->statusCount(CleaningBookingStatus::Pending))
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->url($this->statusFilterUrl(CleaningBookingStatus::Pending))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(__('cleaning_admin.booking.stats.searching'), $this->searchingCount())
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->url($this->tableFilterUrl(['partial_team' => ['isActive' => true]]))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(__('cleaning_admin.booking.stats.assigned'), $this->statusCount(CleaningBookingStatus::WorkerAssigned))
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->url($this->statusFilterUrl(CleaningBookingStatus::WorkerAssigned))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(__('cleaning_admin.booking.stats.in_progress'), $this->statusCount(CleaningBookingStatus::InProgress))
                ->icon('heroicon-o-play')
                ->color('success')
                ->url($this->statusFilterUrl(CleaningBookingStatus::InProgress))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(__('cleaning_admin.booking.stats.today'), CleaningBooking::query()->whereDate('scheduled_date', today())->count())
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->url($this->tableFilterUrl(['scheduled_today' => ['isActive' => true]]))
                ->extraAttributes(['class' => 'cursor-pointer']),
        ];
    }

    private function statusCount(CleaningBookingStatus $status): int
    {
        return CleaningBooking::query()->where('status', $status->value)->count();
    }

    private function searchingCount(): int
    {
        return CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Pending->value)
            ->whereHas('acceptedWorkerAssignments')
            ->count();
    }

    private function statusFilterUrl(CleaningBookingStatus $status): string
    {
        return $this->tableFilterUrl([
            'status' => ['value' => $status->value],
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    private function tableFilterUrl(array $filters): string
    {
        return CleaningBookingResource::getUrl('index', [
            'filters' => $filters,
        ]);
    }
}

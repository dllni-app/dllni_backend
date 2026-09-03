<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningOperationsReportingService;

final class CleaningBookingStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $reporting = app(CleaningOperationsReportingService::class);

        return [
            Stat::make(__('cleaning_admin.booking.stats.total'), $reporting->totalBookings())
                ->description('عدد الحجوزات الرئيسية؛ المناسبة متعددة الأيام تُحسب حجزاً واحداً.')
                ->icon('heroicon-o-calendar-days')
                ->color('primary'),
            Stat::make('جلسات التنفيذ', $reporting->totalExecutionSessions())
                ->description('كل يوم في المناسبة يُحسب جلسة تنفيذ مستقلة.')
                ->icon('heroicon-o-rectangle-stack')
                ->color('info'),
            Stat::make(__('cleaning_admin.booking.stats.pending'), $this->statusCount(CleaningBookingStatus::Pending))
                ->icon('heroicon-o-clock')
                ->color('warning'),
            Stat::make(__('cleaning_admin.booking.stats.searching'), $this->searchingCount())
                ->icon('heroicon-o-user-group')
                ->color('info'),
            Stat::make(__('cleaning_admin.booking.stats.assigned'), $this->statusCount(CleaningBookingStatus::WorkerAssigned))
                ->icon('heroicon-o-user-plus')
                ->color('info'),
            Stat::make(__('cleaning_admin.booking.stats.in_progress'), $this->statusCount(CleaningBookingStatus::InProgress))
                ->icon('heroicon-o-play')
                ->color('success'),
            Stat::make('جلسات اليوم', $reporting->scheduledExecutionSessionsForDay())
                ->description('تعتمد على جلسات الأيام لا على تاريخ الحجز الرئيسي فقط.')
                ->icon('heroicon-o-calendar')
                ->color('gray'),
            Stat::make('ربح الإدارة اليوم', number_format($reporting->adminRevenueForDay(), 0, '.', ',').' '.config('app.currency', 'SYP'))
                ->description($reporting->completedExecutionSessionsForDay().' جلسات مكتملة اليوم')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
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
}

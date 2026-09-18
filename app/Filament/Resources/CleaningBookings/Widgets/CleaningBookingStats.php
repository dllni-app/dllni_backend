<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        return [
            Stat::make('حجوزات اليوم', CleaningBooking::query()->whereDate('scheduled_date', now((string) config('app.dashboard_timezone', 'Asia/Damascus'))->toDateString())->count())
                ->description('الحجوزات المجدولة للتنفيذ اليوم')
                ->icon('heroicon-o-calendar')
                ->color('primary'),
            Stat::make('بانتظار اكتمال الفريق', $this->statusCount(CleaningBookingStatus::Pending))
                ->description('حجوزات ما زالت بحاجة إلى عامل أو أكثر')
                ->icon('heroicon-o-user-group')
                ->color('warning'),
            Stat::make('قيد التنفيذ', $this->statusCount(CleaningBookingStatus::InProgress))
                ->description('الحجوزات التي بدأ العمل بها الآن')
                ->icon('heroicon-o-play')
                ->color('success'),
            Stat::make('إجمالي الحجوزات', CleaningBooking::query()->count())
                ->description('جميع حجوزات التنظيف المسجلة')
                ->icon('heroicon-o-calendar-days')
                ->color('gray'),
        ];
    }

    private function statusCount(CleaningBookingStatus $status): int
    {
        return CleaningBooking::query()->where('status', $status->value)->count();
    }
}

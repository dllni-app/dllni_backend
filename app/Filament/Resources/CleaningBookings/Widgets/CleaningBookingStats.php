<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningOperationsReportingService;
use Modules\Cleaning\Services\CleaningV3DisplayFormatter;

final class CleaningBookingStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $reporting = app(CleaningOperationsReportingService::class);
        $cash = $reporting->verifiedCashForDay();

        return [
            Stat::make(__('cleaning_admin.booking.stats.total'), $reporting->totalBookings())
                ->description('كل حجز يومي مستقل يُحسب مرة واحدة.')
                ->icon('heroicon-o-calendar-days')
                ->color('primary'),
            Stat::make('الحجوزات الملغاة', $this->statusCount(CleaningBookingStatus::Cancelled))
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
            Stat::make('حجوزات اليوم', $reporting->scheduledBookingsForDay())
                ->description('الحجوزات المجدولة غير الملغاة.')
                ->icon('heroicon-o-calendar')
                ->color('gray'),
            Stat::make('هامش الإدارة المحسوب اليوم', CleaningV3DisplayFormatter::money($reporting->adminRevenueForDay()))
                ->description('هامش محسوب وليس تحصيلاً نقدياً.')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('نقد العملاء المحقق بالخزينة اليوم', CleaningV3DisplayFormatter::money($cash['treasuryCustomerCashSyp']))
                ->icon('heroicon-o-banknotes')->color('success'),
            Stat::make('مبالغ الشركة المستلمة من المشرفين اليوم', CleaningV3DisplayFormatter::money($cash['supervisorCompanyReceiptsSyp']))
                ->icon('heroicon-o-banknotes')->color('success'),
        ];
    }

    private function statusCount(CleaningBookingStatus $status): int
    {
        return CleaningBooking::query()->where('status', $status->value)->count();
    }

    private function searchingCount(): int
    {
        return app(CleaningOperationsReportingService::class)->searchingBookings();
    }
}

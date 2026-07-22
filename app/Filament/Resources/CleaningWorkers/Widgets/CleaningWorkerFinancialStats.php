<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Widgets;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningWorkerDeposits\CleaningWorkerDepositsResource;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Support\AdminUiFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Services\CleaningFinancialSummaryService;

final class CleaningWorkerFinancialStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $summary = app(CleaningFinancialSummaryService::class)->global();

        return [
            Stat::make(
                app()->isLocale('ar') ? 'الطلبيات' : 'Bookings',
                (int) $summary['bookingsTotal'],
            )
                ->description(app()->isLocale('ar')
                    ? 'مكتمل: '.(int) $summary['completedBookingsTotal']
                    : 'Completed: '.(int) $summary['completedBookingsTotal'])
                ->icon('heroicon-o-clipboard-document-list')
                ->color('primary')
                ->url(CleaningBookingResource::getUrl('index'))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'إجمالي الإيداع' : 'Total deposits',
                self::money((float) $summary['depositedTotal']),
            )
                ->description(app()->isLocale('ar') ? 'مجموع معاملات الإيداع والتسوية' : 'Sum of deposit and settlement transactions')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(CleaningWorkerDepositsResource::getUrl('index'))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'إجمالي السحب' : 'Total withdrawals',
                self::money((float) $summary['withdrawnTotal']),
            )
                ->description(app()->isLocale('ar') ? 'مجموع المبالغ المسحوبة للعاملين' : 'Sum of amounts withdrawn to workers')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->url(CleaningWorkerDepositsResource::getUrl('index'))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'إجمالي أرصدة الإيداع الحالية' : 'Current deposit balances',
                self::money((float) $summary['currentDepositBalance']),
            )
                ->description(app()->isLocale('ar') ? 'الرصيد الحالي لجميع العاملين' : 'Current balance across all workers')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->url(CleaningWorkerResource::getUrl('index'))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'إجمالي المديونية الحالية' : 'Current debt balance',
                self::money((float) $summary['currentDebtBalance']),
            )
                ->description(app()->isLocale('ar') ? 'المديونية الفعلية غير المسددة' : 'Actual unsettled worker debt')
                ->icon('heroicon-o-exclamation-triangle')
                ->color((float) $summary['currentDebtBalance'] > 0 ? 'danger' : 'success')
                ->url(CleaningWorkerResource::getUrl('index', [
                    'filters' => [
                        'has_debt' => ['value' => true],
                    ],
                ]))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'العاملون المحجوبون مالياً' : 'Financially blocked workers',
                (int) $summary['financiallyBlockedWorkers'],
            )
                ->description(app()->isLocale('ar') ? 'لا يمكنهم استقبال طلبات جديدة بسبب الحساب المالي' : 'Cannot receive new requests because of finance status')
                ->icon('heroicon-o-no-symbol')
                ->color((int) $summary['financiallyBlockedWorkers'] > 0 ? 'danger' : 'success')
                ->url(CleaningWorkerResource::getUrl('index', [
                    'filters' => [
                        'financially_blocked' => ['value' => true],
                    ],
                ]))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'العمولات المحجوزة للطلبات النشطة' : 'Reserved active commissions',
                self::money((float) $summary['reservedActiveCommission']),
            )
                ->description(app()->isLocale('ar') ? 'طلبات نشطة لم تُرحّل عمولاتها بعد' : 'Active bookings whose commission has not been charged yet')
                ->icon('heroicon-o-clock')
                ->color((float) $summary['reservedActiveCommission'] > 0 ? 'warning' : 'gray')
                ->url(CleaningWorkerResource::getUrl('index', [
                    'filters' => [
                        'has_reserved_active_commission' => ['value' => true],
                    ],
                ]))
                ->extraAttributes(['class' => 'cursor-pointer']),
            Stat::make(
                app()->isLocale('ar') ? 'الطلبات المكتملة' : 'Completed bookings',
                (int) $summary['completedBookingsTotal'],
            )
                ->description(app()->isLocale('ar') ? 'حجوزات بحالة مكتمل' : 'Bookings with completed status')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->url(CleaningBookingResource::getUrl('index', [
                    'filters' => [
                        'status' => ['value' => CleaningBookingStatus::Completed->value],
                    ],
                ]))
                ->extraAttributes(['class' => 'cursor-pointer']),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Widgets;

use App\Filament\Support\AdminUiFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
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
                app()->isLocale('ar') ? 'إجمالي أرصدة الإيداع الحالية' : 'Current deposit balances',
                self::money((float) $summary['currentDepositBalance']),
            )
                ->description(app()->isLocale('ar') ? 'الرصيد الحالي لجميع العاملين' : 'Current balance across all workers')
                ->icon('heroicon-o-banknotes')
                ->color('primary'),
            Stat::make(
                app()->isLocale('ar') ? 'إجمالي المديونية الحالية' : 'Current debt balance',
                self::money((float) $summary['currentDebtBalance']),
            )
                ->description(app()->isLocale('ar') ? 'المديونية الفعلية غير المسددة' : 'Actual unsettled worker debt')
                ->icon('heroicon-o-exclamation-triangle')
                ->color((float) $summary['currentDebtBalance'] > 0 ? 'danger' : 'success'),
            Stat::make(
                app()->isLocale('ar') ? 'العاملون المحجوبون مالياً' : 'Financially blocked workers',
                (int) $summary['financiallyBlockedWorkers'],
            )
                ->description(app()->isLocale('ar') ? 'لا يمكنهم استقبال طلبات جديدة بسبب الحساب المالي' : 'Cannot receive new requests because of finance status')
                ->icon('heroicon-o-no-symbol')
                ->color((int) $summary['financiallyBlockedWorkers'] > 0 ? 'danger' : 'success'),
            Stat::make(
                app()->isLocale('ar') ? 'العمولات المحجوزة للطلبات النشطة' : 'Reserved active commissions',
                self::money((float) $summary['reservedActiveCommission']),
            )
                ->description(app()->isLocale('ar') ? 'طلبات نشطة لم تُرحّل عمولاتها بعد' : 'Active bookings whose commission has not been charged yet')
                ->icon('heroicon-o-clock')
                ->color((float) $summary['reservedActiveCommission'] > 0 ? 'warning' : 'gray'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount);
    }
}

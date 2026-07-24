<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Widgets;

use App\Filament\Support\AdminUiFormatter;
use App\Models\CleaningFinancialPenalty;
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
        $activePenalties = (float) CleaningFinancialPenalty::query()->active()->sum('amount');

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
                app()->isLocale('ar') ? 'استحقاقات الإدارة المحجوزة للطلبات النشطة' : 'Reserved active administration dues',
                self::money((float) $summary['reservedActiveAdministrationDue']),
            )
                ->description(app()->isLocale('ar') ? 'طلبات نشطة لم تُرحّل استحقاقاتها بعد' : 'Active bookings whose administration dues are not charged yet')
                ->icon('heroicon-o-clock')
                ->color((float) $summary['reservedActiveAdministrationDue'] > 0 ? 'warning' : 'gray'),
            Stat::make(
                app()->isLocale('ar') ? 'الغرامات المالية النشطة' : 'Active financial penalties',
                self::money($activePenalties),
            )
                ->description(app()->isLocale('ar') ? 'إجمالي الغرامات غير المصفرة' : 'Total penalties that have not been cleared')
                ->icon('heroicon-o-exclamation-circle')
                ->color($activePenalties > 0 ? 'danger' : 'success'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount);
    }
}

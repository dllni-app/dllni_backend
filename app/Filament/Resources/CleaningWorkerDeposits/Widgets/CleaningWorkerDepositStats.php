<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Widgets;

use App\Filament\Support\AdminUiFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Services\CleaningFinancialSummaryService;

final class CleaningWorkerDepositStats extends StatsOverviewWidget
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
                ->description(app()->isLocale('ar') ? 'المبالغ المحتفظ بها حالياً للعاملين' : 'Funds currently held for workers')
                ->icon('heroicon-o-banknotes')
                ->color('primary'),
            Stat::make(
                app()->isLocale('ar') ? 'إجمالي المديونية الحالية' : 'Current debt balance',
                self::money((float) $summary['currentDebtBalance']),
            )
                ->description(app()->isLocale('ar') ? 'المبالغ المستحقة حالياً على العاملين' : 'Amounts currently owed by workers')
                ->icon('heroicon-o-exclamation-triangle')
                ->color((float) $summary['currentDebtBalance'] > 0 ? 'danger' : 'success'),
            Stat::make(
                app()->isLocale('ar') ? 'رصيد عمولة الإدارة الحالي' : 'Current administration commission',
                self::money((float) $summary['currentAdminCommissionBalance']),
            )
                ->description(app()->isLocale('ar') ? 'العمولات المتراكمة منذ آخر تصفير للحسابات' : 'Commission accumulated since the last account settlement')
                ->icon('heroicon-o-receipt-percent')
                ->color('warning'),
            Stat::make(
                app()->isLocale('ar') ? 'إيرادات الإدارة المسحوبة' : 'Withdrawn administration revenue',
                self::money((float) $summary['withdrawnAdminRevenue']),
            )
                ->description(app()->isLocale('ar') ? 'العمولات التي تم ترحيلها عند تصفير الحسابات' : 'Commission moved to revenue during account settlements')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount, 0);
    }
}

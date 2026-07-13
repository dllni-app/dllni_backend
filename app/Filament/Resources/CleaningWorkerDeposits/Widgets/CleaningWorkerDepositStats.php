<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Widgets;

use App\Filament\Support\AdminUiFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Services\CleaningFinancialOverviewService;

final class CleaningWorkerDepositStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $metrics = app(CleaningFinancialOverviewService::class)->transactionMetrics();

        return [
            Stat::make(__('Total current debt'), self::money($metrics['currentDebt']))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($metrics['currentDebt'] > 0 ? 'danger' : 'success'),
            Stat::make(__('Total deposits'), self::money($metrics['totalDeposits']))
                ->description(__('Deposit transactions'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success'),
            Stat::make(__('Total settlements'), self::money($metrics['totalSettlements']))
                ->description(__('Debt settlement transactions'))
                ->icon('heroicon-o-check-circle')
                ->color('primary'),
            Stat::make(__('Total refunds'), self::money($metrics['totalRefunds']))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount, 0);
    }
}

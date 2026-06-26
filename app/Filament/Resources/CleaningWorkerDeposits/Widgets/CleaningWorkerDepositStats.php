<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Widgets;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class CleaningWorkerDepositStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $currentDebt = (float) CleaningWorkerDeposit::query()
            ->where('current_balance', '<', 0)
            ->selectRaw('COALESCE(SUM(ABS(current_balance)), 0) as total')
            ->value('total');
        $totalDeposits = $this->sumByType('deposit');
        $totalSettlements = $this->sumByType('settlement');
        $totalAdminFees = $this->sumByType('admin_fee');
        $totalRefunds = $this->sumByType('refund') + $this->sumByType('withdrawal');
        $totalAdjustments = $this->sumByType('adjustment');

        return [
            Stat::make('Total current debt', self::money($currentDebt))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($currentDebt > 0 ? 'danger' : 'success'),
            Stat::make('Total deposits', self::money($totalDeposits))
                ->description('Manual deposit transactions')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success'),
            Stat::make('Total settlements', self::money($totalSettlements))
                ->description('Debt settlement transactions')
                ->icon('heroicon-o-check-circle')
                ->color('primary'),
            Stat::make('Admin fees charged', self::money($totalAdminFees))
                ->description('Platform commissions charged to workers')
                ->icon('heroicon-o-currency-dollar')
                ->color('danger'),
            Stat::make('Refunds and withdrawals', self::money($totalRefunds))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning'),
            Stat::make('Manual adjustments', self::money($totalAdjustments))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray'),
        ];
    }

    private function sumByType(string $type): float
    {
        return (float) CleaningDepositTransaction::query()
            ->where('type', $type)
            ->sum('amount');
    }

    private static function money(float $amount): string
    {
        return 'SYP '.number_format($amount, 2);
    }
}

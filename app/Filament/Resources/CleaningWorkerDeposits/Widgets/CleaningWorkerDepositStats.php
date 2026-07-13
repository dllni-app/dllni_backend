<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Widgets;

use App\Filament\Support\AdminUiFormatter;
use App\Models\CleaningDepositTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Services\WorkerDebtService;

final class CleaningWorkerDepositStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $ledger = app(WorkerDebtService::class)->globalSummary();
        $currentDebt = (float) $ledger['outstandingAdministrationDue'];
        $totalDeposits = $this->sumByType('deposit');
        $totalSettlements = $this->sumByType('settlement');
        $totalRefunds = $this->sumByType('refund');

        return [
            Stat::make(__('Total current debt'), self::money($currentDebt))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($currentDebt > 0 ? 'danger' : 'success'),
            Stat::make(__('Total deposits'), self::money($totalDeposits))
                ->description(__('Deposit transactions'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success'),
            Stat::make(__('Total settlements'), self::money($totalSettlements))
                ->description(__('Debt settlement transactions'))
                ->icon('heroicon-o-check-circle')
                ->color('primary'),
            Stat::make(__('Total refunds'), self::money($totalRefunds))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount, 0);
    }

    private function sumByType(string $type): float
    {
        return (float) CleaningDepositTransaction::query()
            ->where('type', $type)
            ->sum('amount');
    }
}

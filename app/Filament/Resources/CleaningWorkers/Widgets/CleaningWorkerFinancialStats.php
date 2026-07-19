<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Widgets;

use App\Filament\Support\AdminUiFormatter;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningWorkerFinancialStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $accounts = CleaningWorkerDeposit::query()->get([
            'current_balance',
            'debt_balance',
            'max_negative_balance',
        ]);

        $totalDebt = $accounts->sum(
            fn (CleaningWorkerDeposit $account): float => max(0.0, (float) $account->debt_balance),
        );
        $workersWithDebt = $accounts->filter(
            fn (CleaningWorkerDeposit $account): bool => (float) $account->debt_balance > 0,
        )->count();
        $totalCapacityBeforeReservations = $accounts->sum(function (CleaningWorkerDeposit $account): float {
            $depositBalance = max(0.0, (float) $account->current_balance);
            $debtBalance = max(0.0, (float) $account->debt_balance);
            $allowedDebtLimit = max(0.0, (float) ($account->max_negative_balance ?? 0));

            return $depositBalance + max(0.0, $allowedDebtLimit - $debtBalance);
        });
        $workersBlockedByBalance = Worker::query()
            ->where('security_deposit_status', 'insufficient_balance')
            ->count();
        $reservedCommission = (float) CleaningBookingWorkerAssignment::query()
            ->join('cleaning_bookings', 'cleaning_bookings.id', '=', 'cleaning_booking_worker_assignments.cleaning_booking_id')
            ->whereIn('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->whereNotIn('cleaning_bookings.status', [
                CleaningBookingStatus::Completed->value,
                CleaningBookingStatus::Cancelled->value,
            ])
            ->sum('cleaning_booking_worker_assignments.admin_margin_amount');
        $availableCapacity = max(0.0, $totalCapacityBeforeReservations - $reservedCommission);

        return [
            Stat::make(__('Current debt'), self::money($totalDebt))
                ->description(__('workers with debt', ['count' => $workersWithDebt]))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($totalDebt > 0 ? 'danger' : 'success'),
            Stat::make(__('Workers blocked by balance'), $workersBlockedByBalance)
                ->description(__('Cannot receive new requests because of finance status'))
                ->icon('heroicon-o-no-symbol')
                ->color($workersBlockedByBalance > 0 ? 'danger' : 'success'),
            Stat::make(__('Available commission capacity'), self::money($availableCapacity))
                ->description(__('Deposit plus remaining debt capacity minus reserved commissions'))
                ->icon('heroicon-o-banknotes')
                ->color($availableCapacity > 0 ? 'success' : 'danger'),
            Stat::make(__('Reserved active commissions'), self::money($reservedCommission))
                ->description(__('Accepted active bookings not yet charged'))
                ->icon('heroicon-o-clock')
                ->color($reservedCommission > 0 ? 'warning' : 'gray'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency($amount);
    }
}

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
        $deposits = CleaningWorkerDeposit::query()->get(['current_balance', 'max_negative_balance']);
        $totalDebt = $deposits->sum(fn (CleaningWorkerDeposit $deposit): float => max(0.0, -((float) $deposit->current_balance)));
        $workersWithDebt = $deposits->filter(fn (CleaningWorkerDeposit $deposit): bool => (float) $deposit->current_balance < 0)->count();
        $totalCapacity = $deposits->sum(
            fn (CleaningWorkerDeposit $deposit): float => (float) $deposit->current_balance + (float) ($deposit->max_negative_balance ?? 0)
        );
        $workersBlockedByBalance = Worker::query()
            ->where('security_deposit_status', 'insufficient_balance')
            ->count();
        $reservedCommission = (float) CleaningBookingWorkerAssignment::query()
            ->join('cleaning_bookings', 'cleaning_bookings.id', '=', 'cleaning_booking_worker_assignments.cleaning_booking_id')
            ->whereIn('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->whereNotIn('cleaning_bookings.status', [
                CleaningBookingStatus::Completed->value,
                CleaningBookingStatus::Cancelled->value,
            ])
            ->sum('cleaning_booking_worker_assignments.admin_margin_amount');

        return [
            Stat::make(__('Current debt'), self::money($totalDebt))
                ->description(__('workers with debt', ['count' => $workersWithDebt]))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($totalDebt > 0 ? 'danger' : 'success'),
            Stat::make(__('Workers blocked by balance'), $workersBlockedByBalance)
                ->description(__('Cannot receive new requests because of finance status'))
                ->icon('heroicon-o-no-symbol')
                ->color($workersBlockedByBalance > 0 ? 'danger' : 'success'),
            Stat::make(__('Available commission capacity'), self::money($totalCapacity - $reservedCommission))
                ->description(__('Balance plus debt limit minus reserved commissions'))
                ->icon('heroicon-o-banknotes')
                ->color(($totalCapacity - $reservedCommission) > 0 ? 'success' : 'danger'),
            Stat::make(__('Reserved active commissions'), self::money($reservedCommission))
                ->description(__('Accepted active bookings not yet charged'))
                ->icon('heroicon-o-clock')
                ->color($reservedCommission > 0 ? 'warning' : 'gray'),
        ];
    }

    private static function money(float $amount): string
    {
        return AdminUiFormatter::formatCurrency(
            $amount,
            arabicNumerals: app()->getLocale() === 'ar',
        );
    }
}

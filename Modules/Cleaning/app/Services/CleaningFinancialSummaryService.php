<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningFinancialSummaryService
{
    /** @return array<string, float|int> */
    public function global(): array
    {
        $accountTotals = CleaningWorkerDeposit::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN current_balance > 0 THEN current_balance ELSE 0 END), 0) AS current_deposit')
            ->selectRaw('COALESCE(SUM(CASE WHEN debt_balance > 0 THEN debt_balance ELSE 0 END), 0) AS current_debt')
            ->selectRaw('COALESCE(SUM(CASE WHEN admin_revenue_withdrawn_total > 0 THEN admin_revenue_withdrawn_total ELSE 0 END), 0) AS withdrawn_admin_revenue')
            ->first();

        $prefix = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';
        $legacyPrefix = CleaningDepositTransaction::LEGACY_AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';
        $administrationDueTotal = (float) CleaningDepositTransaction::query()
            ->where(function (Builder $query) use ($prefix, $legacyPrefix): void {
                $query->whereIn('type', ['commission', 'admin_fee'])
                    ->orWhere(function (Builder $query) use ($prefix, $legacyPrefix): void {
                        $query->where('type', 'debt')
                            ->where(function (Builder $references) use ($prefix, $legacyPrefix): void {
                                $references->where('reference', 'like', $prefix)
                                    ->orWhere('reference', 'like', $legacyPrefix);
                            });
                    });
            })
            ->sum('amount');

        $withdrawnAdminRevenue = max(0.0, (float) ($accountTotals?->withdrawn_admin_revenue ?? 0));

        return [
            'currentDepositBalance' => round(max(0.0, (float) ($accountTotals?->current_deposit ?? 0)), 2),
            'currentDebtBalance' => round(max(0.0, (float) ($accountTotals?->current_debt ?? 0)), 2),
            'currentAdministrationDueBalance' => round(max(0.0, $administrationDueTotal - $withdrawnAdminRevenue), 2),
            'withdrawnAdminRevenue' => round($withdrawnAdminRevenue, 2),
            'totalRevenue' => round($this->totalRevenue(), 2),
            'reservedActiveAdministrationDue' => round($this->reservedActiveAdministrationDue(), 2),
            'financiallyBlockedWorkers' => Worker::query()
                ->where('security_deposit_status', 'insufficient_balance')
                ->count(),
        ];
    }

    private function totalRevenue(): float
    {
        return (float) CleaningBookingWorkerAssignment::query()
            ->sum(DB::raw('COALESCE(service_share_amount, 0) + COALESCE(travel_fee, 0) + COALESCE(admin_margin_amount, 0)'));
    }

    private function reservedActiveAdministrationDue(): float
    {
        return (float) CleaningBookingWorkerAssignment::query()
            ->join('cleaning_bookings', 'cleaning_bookings.id', '=', 'cleaning_booking_worker_assignments.cleaning_booking_id')
            ->whereIn('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->whereNotIn('cleaning_bookings.status', [
                CleaningBookingStatus::Completed->value,
                CleaningBookingStatus::Cancelled->value,
            ])
            ->sum('cleaning_booking_worker_assignments.admin_margin_amount');
    }
}

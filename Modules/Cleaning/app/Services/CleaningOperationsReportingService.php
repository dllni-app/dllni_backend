<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningOperationsReportingService
{
    public function totalBookings(): int
    {
        return CleaningBooking::query()->count();
    }

    /**
     * Execution sessions count child sessions when they exist and falls back to
     * one execution session for legacy/single-day bookings without child rows.
     */
    public function totalExecutionSessions(): int
    {
        return CleaningBookingSession::query()->whereNotIn('status', ['superseded', 'cancelled', 'skipped'])->count()
            + CleaningBooking::query()->whereDoesntHave('sessions')->where('status', '!=', 'cancelled')->count();
    }

    public function scheduledExecutionSessionsForDay(CarbonInterface|string|null $day = null): int
    {
        $date = $this->dateString($day);

        return CleaningBookingSession::query()
            ->whereDate('scheduled_date', $date)
            ->whereNotIn('status', ['cancelled', 'skipped', 'superseded'])
            ->count()
            + CleaningBooking::query()
                ->whereDoesntHave('sessions')
                ->whereDate('scheduled_date', $date)
                ->where('status', '!=', 'cancelled')
                ->count();
    }

    public function scheduledBookingsForDay(CarbonInterface|string|null $day = null): int
    {
        return $this->scheduledBookingsQuery(CleaningBooking::query(), $day)->count();
    }

    public function scheduledBookingsQuery(Builder $query, CarbonInterface|string|null $day = null): Builder
    {
        $date = $this->dateString($day);
        return $query->where('status', '!=', 'cancelled')
            ->where(function (Builder $q) use ($date): void {
                $q->whereHas('sessions', fn (Builder $sessions) => $sessions->whereDate('scheduled_date', $date)
                    ->whereNotIn('status', ['cancelled', 'skipped', 'superseded']))
                    ->orWhere(fn (Builder $legacy) => $legacy->whereDoesntHave('sessions')->whereDate('scheduled_date', $date));
            });
    }

    public function searchingBookings(): int
    {
        return $this->searchingBookingsQuery(CleaningBooking::query())->count();
    }

    public function searchingBookingsQuery(Builder $query): Builder
    {
        // Count actual open searches, including zero accepted workers.
        // The projection is also used by the dispatch endpoint and UI.
        $ids = [];
        \Modules\Cleaning\Models\CleaningV3VisitDispatch::query()
            ->whereNull('resolved_at')
            ->whereHas('session', fn (Builder $q) => $q->whereIn('status', ['scheduled', 'worker_assigned']))
            ->with('session.booking')->chunkById(200, function ($records) use (&$ids): void {
                foreach ($records as $record) {
                    $visit = $record->session;
                    if ($visit !== null && $visit->booking?->contract_version === 'v3'
                        && app(CleaningV3OperationalAdmissionPolicy::class)->approved($visit->booking, $visit)
                        && ($dispatch = app(CleaningV3VisitDispatchService::class)->project($visit))['remainingWorkers'] > 0
                        && in_array($dispatch['dispatchStatus'], ['searching', 'escalated'], true)) {
                        $ids[$visit->cleaning_booking_id] = true;
                    }
                }
            });
        return $query->where(function (Builder $scope) use ($ids): void {
            $scope->whereIn('id', array_keys($ids))->orWhere(function (Builder $legacy): void {
                $legacy->where(function (Builder $contracts): void {
                    $contracts->whereNull('contract_version')->orWhere('contract_version', '!=', 'v3');
                })->where('status', 'pending')->whereNotNull('search_started_at');
            });
        });
    }

    /** Verified physical receipts only; do not confuse customer cash with company receipts. */
    public function verifiedCashForDay(CarbonInterface|string|null $day = null): array
    {
        $date = $this->dateString($day);
        $treasury = DB::table('cleaning_v3_cash_movements')->where('kind', 'collection')
            ->where('status', 'posted')->whereNotNull('verified_by')->whereColumn('verified_by', '!=', 'recorded_by')
            ->whereDate('verified_at', $date)->sum('amount');
        $company = DB::table('cleaning_v3_supervisor_cash_accounts')
            ->whereNotNull('received_by')->whereColumn('received_by', '!=', 'collector_user_id')
            ->whereNotNull('receipt_reference')->whereNotNull('receipt_evidence')
            ->whereDate('received_at', $date)->sum('company_amount');
        return ['treasuryCustomerCashSyp' => bcadd((string) $treasury, '0', 2),
            'supervisorCompanyReceiptsSyp' => bcadd((string) $company, '0', 2)];
    }

    public function completedExecutionSessionsForDay(CarbonInterface|string|null $day = null): int
    {
        $date = $this->dateString($day);

        return CleaningBookingSession::query()
            ->where('status', CleaningBookingSessionStatus::Completed->value)
            ->where(fn (Builder $query): Builder => $this->completedSessionOnDate($query, $date))
            ->count()
            + CleaningBooking::query()
                ->whereDoesntHave('sessions')
                ->where('status', CleaningBookingStatus::Completed->value)
                ->whereDate('work_finished_at', $date)
                ->count();
    }

    public function adminRevenueForDay(CarbonInterface|string|null $day = null): float
    {
        $date = $this->dateString($day);

        $sessionRevenue = (float) CleaningBookingSession::query()
            ->where('status', CleaningBookingSessionStatus::Completed->value)
            ->where(fn (Builder $query): Builder => $this->completedSessionOnDate($query, $date))
            ->sum('admin_margin_amount');

        $legacyRevenue = (float) CleaningBooking::query()
            ->whereDoesntHave('sessions')
            ->where('status', CleaningBookingStatus::Completed->value)
            ->whereDate('work_finished_at', $date)
            ->sum('admin_margin_amount');

        return round($sessionRevenue + $legacyRevenue, 2);
    }

    private function completedSessionOnDate(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('customer_completed_at', $date)
            ->orWhere(function (Builder $fallback) use ($date): void {
                $fallback
                    ->whereNull('customer_completed_at')
                    ->whereDate('work_finished_at', $date);
            });
    }

    private function dateString(CarbonInterface|string|null $day): string
    {
        if ($day instanceof CarbonInterface) {
            return CarbonImmutable::instance($day)->toDateString();
        }

        if (is_string($day) && mb_trim($day) !== '') {
            return CarbonImmutable::parse($day, config('app.timezone'))->toDateString();
        }

        return CarbonImmutable::now(config('app.timezone'))->toDateString();
    }
}

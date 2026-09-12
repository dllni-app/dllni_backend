<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        return CleaningBookingSession::query()->count()
            + CleaningBooking::query()->whereDoesntHave('sessions')->count();
    }

    public function scheduledExecutionSessionsForDay(CarbonInterface|string|null $day = null): int
    {
        $date = $this->dateString($day);

        return CleaningBookingSession::query()
            ->whereDate('scheduled_date', $date)
            ->count()
            + CleaningBooking::query()
                ->whereDoesntHave('sessions')
                ->whereDate('scheduled_date', $date)
                ->count();
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

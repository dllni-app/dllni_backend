<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Dispute;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class WorkerSessionMetricsService
{
    private const ACCEPTED_PARENT_ASSIGNMENT_STATUSES = [
        'accepted',
        'accepted_waiting_team',
        'accepted_waiting_for_order_start',
        'awaiting_start_verification',
        'start_approved',
        'in_progress',
        'awaiting_customer_completion',
        'time_extension_requested',
        'completed',
    ];

    /** @param array<string, mixed> $payload @param array<string, string> $dayLabels @return array<string, mixed> */
    public function patchHomepage(array $payload, Worker $worker, Carbon $today, Carbon $weekStart, Carbon $weekEnd, Carbon $fourWeekStart, Carbon $fourWeekEnd, array $dayLabels): array
    {
        $yesterday = $today->copy()->subDay();
        $sessionAssignments = $this->sessionAssignments($worker, null, null);
        $legacyBookings = $this->legacyBookings($worker, null, null);

        $todaySessionAssignments = $sessionAssignments->filter(
            fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->scheduled_date?->isSameDay($today) ?? false,
        );
        $todayLegacyBookings = $legacyBookings->filter(
            fn (CleaningBooking $booking): bool => $booking->scheduled_date?->isSameDay($today) ?? false,
        );

        $payload['todayCount'] = $todaySessionAssignments
            ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status !== CleaningBookingSessionStatus::Cancelled)
            ->count()
            + $todayLegacyBookings->where('status', '!=', CleaningBookingStatus::Cancelled)->count();

        $payload['todaySessionsCount'] = $todaySessionAssignments
            ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status !== CleaningBookingSessionStatus::Cancelled)
            ->count();

        $payload['totalEarnings'] = round(
            $this->completedSessionEarnings($sessionAssignments)
            + $this->completedLegacyEarnings($legacyBookings, (int) $worker->id),
            2,
        );
        $todayEarnings = round(
            $this->completedSessionEarnings($todaySessionAssignments)
            + $this->completedLegacyEarnings($todayLegacyBookings, (int) $worker->id),
            2,
        );
        $yesterdayEarnings = round(
            $this->completedSessionEarnings($sessionAssignments->filter(
                fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->scheduled_date?->isSameDay($yesterday) ?? false,
            ))
            + $this->completedLegacyEarnings($legacyBookings->filter(
                fn (CleaningBooking $booking): bool => $booking->scheduled_date?->isSameDay($yesterday) ?? false,
            ), (int) $worker->id),
            2,
        );
        $payload['todayEarnings'] = $todayEarnings;
        $payload['earningsChangePercent'] = match (true) {
            $yesterdayEarnings > 0 => round((($todayEarnings - $yesterdayEarnings) / $yesterdayEarnings) * 100, 1),
            $todayEarnings > 0 => 100.0,
            default => 0.0,
        };

        $weekSessions = $sessionAssignments->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->scheduled_date?->betweenIncluded($weekStart, $weekEnd) ?? false);
        $weekLegacy = $legacyBookings->filter(fn (CleaningBooking $booking): bool => $booking->scheduled_date?->betweenIncluded($weekStart, $weekEnd) ?? false);
        $weeklyCounts = [];
        foreach ($weekSessions as $assignment) {
            if ($assignment->session?->status === CleaningBookingSessionStatus::Cancelled) {
                continue;
            }
            $date = $assignment->session?->scheduled_date?->toDateString();
            if ($date !== null) {
                $weeklyCounts[$date] = ($weeklyCounts[$date] ?? 0) + 1;
            }
        }
        foreach ($weekLegacy as $booking) {
            if ($booking->status === CleaningBookingStatus::Cancelled) {
                continue;
            }
            $date = $booking->scheduled_date?->toDateString();
            if ($date !== null) {
                $weeklyCounts[$date] = ($weeklyCounts[$date] ?? 0) + 1;
            }
        }

        $chart = [];
        $cursor = $weekStart->copy();
        while ($cursor->lte($weekEnd)) {
            $date = $cursor->toDateString();
            $dayKey = mb_strtolower($cursor->englishDayOfWeek);
            $chart[] = [
                'date' => $date,
                'dayKey' => $dayKey,
                'dayLabelAr' => $dayLabels[$dayKey] ?? $cursor->englishDayOfWeek,
                'bookingsCount' => (int) ($weeklyCounts[$date] ?? 0),
            ];
            $cursor->addDay();
        }
        $payload['bookingsWeeklyChart'] = $chart;

        $fourWeekSessions = $sessionAssignments->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->scheduled_date?->betweenIncluded($fourWeekStart, $fourWeekEnd) ?? false);
        $fourWeekLegacy = $legacyBookings->filter(fn (CleaningBooking $booking): bool => $booking->scheduled_date?->betweenIncluded($fourWeekStart, $fourWeekEnd) ?? false);
        $workerAmount = $this->completedSessionEarnings($fourWeekSessions) + $this->completedLegacyEarnings($fourWeekLegacy, (int) $worker->id);
        $adminAmount = $this->completedSessionAdmin($fourWeekSessions) + $this->completedLegacyAdmin($fourWeekLegacy, (int) $worker->id);
        $grossAmount = $workerAmount + $adminAmount;
        $payload['amountSummary']['workerAmount'] = round($workerAmount, 2);
        $payload['amountSummary']['adminAmount'] = round($adminAmount, 2);
        $payload['amountSummary']['grossInvoicesAmount'] = round($grossAmount, 2);

        $invoiceChart = [];
        for ($index = 0; $index < 4; $index++) {
            $segmentStart = $fourWeekStart->copy()->addWeeks($index);
            $segmentEnd = $segmentStart->copy()->addDays(6);
            $segmentSessions = $fourWeekSessions->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->scheduled_date?->betweenIncluded($segmentStart, $segmentEnd) ?? false);
            $segmentLegacy = $fourWeekLegacy->filter(fn (CleaningBooking $booking): bool => $booking->scheduled_date?->betweenIncluded($segmentStart, $segmentEnd) ?? false);
            $amount = $this->completedSessionEarnings($segmentSessions) + $this->completedLegacyEarnings($segmentLegacy, (int) $worker->id);
            $invoiceChart[] = [
                'weekNumber' => $index + 1,
                'label' => 'week_'.($index + 1),
                'from' => $segmentStart->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'invoiceAmount' => round($amount, 2),
                'invoiceAmountThousands' => round($amount / 1000, 3),
            ];
        }
        $payload['invoicesFourWeeksChart'] = $invoiceChart;

        return $payload;
    }

    /** @return array<string, mixed> */
    public function weeklyStatistics(Worker $worker, Carbon $start, Carbon $end): array
    {
        $sessions = $this->sessionAssignments($worker, $start, $end);
        $legacy = $this->legacyBookings($worker, $start, $end);
        $disputes = $this->disputes($worker, $start, $end);
        $sessionDisputes = $disputes->filter(fn (Dispute $dispute): bool => $dispute->cleaning_booking_session_id !== null);
        $legacyDisputes = $disputes->reject(fn (Dispute $dispute): bool => $dispute->cleaning_booking_session_id !== null);

        $chart = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $daySessions = $sessions->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->scheduled_date?->toDateString() === $date);
            $dayLegacy = $legacy->filter(fn (CleaningBooking $booking): bool => $booking->scheduled_date?->toDateString() === $date);
            $daySessionDisputes = $sessionDisputes->filter(fn (Dispute $dispute): bool => $dispute->session?->scheduled_date?->toDateString() === $date);
            $dayLegacyDisputes = $legacyDisputes->filter(fn (Dispute $dispute): bool => $dispute->booking instanceof CleaningBooking && $dispute->booking->scheduled_date?->toDateString() === $date);

            $chart[] = [
                'date' => $date,
                'confirmed' => $daySessions->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status === CleaningBookingSessionStatus::Completed)->count()
                    + $dayLegacy->where('status', CleaningBookingStatus::Completed)->count(),
                'cancelled' => $daySessions->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status === CleaningBookingSessionStatus::Cancelled)->count()
                    + $dayLegacy->where('status', CleaningBookingStatus::Cancelled)->count(),
                'disputed' => $daySessionDisputes->count() + $dayLegacyDisputes->count(),
            ];
            $cursor->addDay();
        }

        $completedSessions = $sessions->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status === CleaningBookingSessionStatus::Completed);
        $completedLegacy = $legacy->where('status', CleaningBookingStatus::Completed);

        return [
            'range' => 'this_week',
            'summary' => [
                'totalBookings' => $sessions->count() + $legacy->count(),
                'totalEarnings' => round($this->completedSessionEarnings($sessions) + $this->completedLegacyEarnings($legacy, (int) $worker->id), 2),
                'confirmedCount' => $completedSessions->count() + $completedLegacy->count(),
                'cancelledCount' => $sessions->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status === CleaningBookingSessionStatus::Cancelled)->count()
                    + $legacy->where('status', CleaningBookingStatus::Cancelled)->count(),
                'disputedCount' => $disputes->count(),
            ],
            'chart' => $chart,
        ];
    }

    /** @return Collection<int, CleaningBookingSessionWorkerAssignment> */
    private function sessionAssignments(Worker $worker, ?Carbon $start, ?Carbon $end): Collection
    {
        return CleaningBookingSessionWorkerAssignment::query()
            ->with(['session.booking'])
            ->where('worker_id', $worker->id)
            ->whereNotIn('status', [
                CleaningBookingWorkerAssignmentStatus::Rejected->value,
                CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
                CleaningBookingWorkerAssignmentStatus::Cancelled->value,
            ])
            ->when($start !== null && $end !== null, fn (Builder $query): Builder => $query->whereHas('session', fn (Builder $session): Builder => $session->whereBetween('scheduled_date', [$start, $end])))
            ->get();
    }

    /** @return Collection<int, CleaningBooking> */
    private function legacyBookings(Worker $worker, ?Carbon $start, ?Carbon $end): Collection
    {
        return CleaningBooking::query()
            ->whereDoesntHave('sessions')
            ->where(function (Builder $query) use ($worker): void {
                $query->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments->where('worker_id', $worker->id)
                            ->whereIn('status', self::ACCEPTED_PARENT_ASSIGNMENT_STATUSES);
                    });
            })
            ->when($start !== null && $end !== null, fn (Builder $query): Builder => $query->whereBetween('scheduled_date', [$start, $end]))
            ->with(['workerAssignments' => fn (HasMany $assignments) => $assignments->where('worker_id', $worker->id)])
            ->get();
    }

    /** @return Collection<int, Dispute> */
    private function disputes(Worker $worker, Carbon $start, Carbon $end): Collection
    {
        return Dispute::query()
            ->where('booking_type', 'cleaning_booking')
            ->with(['booking', 'session'])
            ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $booking) use ($worker): void {
                $booking->where(function (Builder $query) use ($worker): void {
                    $query->where('worker_id', $worker->id)
                        ->orWhereHas('workerAssignments', fn (Builder $assignments): Builder => $assignments->where('worker_id', $worker->id));
                });
            })
            ->where(function (Builder $query) use ($start, $end): void {
                $query->whereHas('session', fn (Builder $session): Builder => $session->whereBetween('scheduled_date', [$start, $end]))
                    ->orWhere(function (Builder $legacy) use ($start, $end): void {
                        $legacy->whereNull('cleaning_booking_session_id')
                            ->whereHasMorph('booking', [CleaningBooking::class], fn (Builder $booking): Builder => $booking->whereBetween('scheduled_date', [$start, $end]));
                    });
            })
            ->get();
    }

    /** @param Collection<int, CleaningBookingSessionWorkerAssignment> $assignments */
    private function completedSessionEarnings(Collection $assignments): float
    {
        return (float) $assignments
            ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status === CleaningBookingSessionStatus::Completed)
            ->sum('worker_amount');
    }

    /** @param Collection<int, CleaningBookingSessionWorkerAssignment> $assignments */
    private function completedSessionAdmin(Collection $assignments): float
    {
        return (float) $assignments
            ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status === CleaningBookingSessionStatus::Completed)
            ->sum('admin_margin_amount');
    }

    /** @param Collection<int, CleaningBooking> $bookings */
    private function completedLegacyEarnings(Collection $bookings, int $workerId): float
    {
        return (float) $bookings
            ->filter(fn (CleaningBooking $booking): bool => $booking->status === CleaningBookingStatus::Completed)
            ->sum(fn (CleaningBooking $booking): float => $this->legacyWorkerAmount($booking, $workerId));
    }

    /** @param Collection<int, CleaningBooking> $bookings */
    private function completedLegacyAdmin(Collection $bookings, int $workerId): float
    {
        return (float) $bookings
            ->filter(fn (CleaningBooking $booking): bool => $booking->status === CleaningBookingStatus::Completed)
            ->sum(function (CleaningBooking $booking) use ($workerId): float {
                $assignment = $booking->workerAssignments->firstWhere('worker_id', $workerId);
                return $assignment instanceof CleaningBookingWorkerAssignment
                    ? max(0.0, (float) $assignment->admin_margin_amount)
                    : max(0.0, (float) $booking->admin_margin_amount);
            });
    }

    private function legacyWorkerAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->workerAssignments->firstWhere('worker_id', $workerId);
        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            if ((float) $assignment->worker_amount > 0) {
                return (float) $assignment->worker_amount;
            }
            return max(0.0, (float) $assignment->service_share_amount + (float) $assignment->travel_fee - (float) $assignment->admin_margin_amount);
        }

        return max(0.0, (float) $booking->total_price - (float) $booking->admin_margin_amount);
    }
}

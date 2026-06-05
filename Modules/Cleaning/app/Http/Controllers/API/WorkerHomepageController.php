<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\GenderPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class WorkerHomepageController
{
    public function __invoke(Request $request): JsonResponse
    {
        $worker = auth()->user()?->worker;

        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6);
        $fourWeekStart = $weekStart->copy()->subWeeks(3);
        $fourWeekEnd = $weekEnd->copy();
        $dayLabels = [
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
        ];

        if (! $worker) {
            return response()->json([
                'date' => $today->format('Y-m-d'),
                'totalBookings' => 0,
                'todayCount' => 0,
                'completedCount' => 0,
                'pendingCount' => 0,
                'inProgressCount' => 0,
                'cancelledCount' => 0,
                'totalEarnings' => 0,
                'todayEarnings' => 0,
                'earningsChangePercent' => 0,
                'newOrdersCount' => 0,
                'pendingExtensionRequestsCount' => 0,
                'amountSummary' => [
                    'period' => 'last_4_weeks',
                    'currency' => 'SYP',
                    'workerAmount' => 0.0,
                    'adminAmount' => 0.0,
                    'grossInvoicesAmount' => 0.0,
                ],
                'bookingsWeeklyChart' => $this->emptyBookingsWeeklyChart($weekStart, $dayLabels),
                'invoicesFourWeeksChart' => $this->emptyInvoicesFourWeeksChart($fourWeekStart),
            ]);
        }

        $baseQuery = CleaningBooking::query()->where(function (Builder $query) use ($worker): void {
            $query->where('worker_id', $worker->id)
                ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->where('status', 'accepted');
                });
        });

        $totalBookings = (clone $baseQuery)->count();

        $todayCount = (clone $baseQuery)
            ->whereDate('scheduled_date', $today)
            ->whereNotIn('status', [CleaningBookingStatus::Cancelled])
            ->count();

        $completedCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->count();

        $pendingCount = (clone $baseQuery)
            ->whereIn('status', [
                CleaningBookingStatus::Pending,
                CleaningBookingStatus::WorkerAssigned,
            ])
            ->whereDate('scheduled_date', '>=', $today)
            ->count();

        $inProgressCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::InProgress)
            ->count();

        $cancelledCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Cancelled)
            ->count();

        $yesterday = $today->copy()->subDay();
        $completedBookings = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->with(['workerAssignments' => function (HasMany $assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id);
            }])
            ->get();

        $completedTodayBookings = $completedBookings->filter(
            fn (CleaningBooking $booking): bool => $booking->scheduled_date?->isSameDay($today) ?? false
        );
        $yesterdayBookings = $completedBookings->filter(
            fn (CleaningBooking $booking): bool => $booking->scheduled_date?->isSameDay($yesterday) ?? false
        );

        $totalEarnings = (float) $completedBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

        $todayEarnings = (float) $completedTodayBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));
        $yesterdayEarnings = (float) $yesterdayBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

        $earningsChangePercent = match (true) {
            $yesterdayEarnings > 0 => round((($todayEarnings - $yesterdayEarnings) / $yesterdayEarnings) * 100, 1),
            $todayEarnings > 0 => 100.0,
            default => 0.0,
        };

        $newOrdersCount = CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Pending)
            ->whereDate('scheduled_date', '>=', $today)
            ->where(fn ($q) => $q->whereNull('worker_id')->orWhere('worker_id', $worker->id))
            ->whereDoesntHave('workerAssignments', fn (Builder $assignments) => $assignments
                ->where('worker_id', $worker->id)
                ->where('status', 'accepted'))
            ->where(function ($genderQuery) use ($worker): void {
                $genderQuery
                    ->whereNull('gender_preference')
                    ->orWhere('gender_preference', GenderPreference::Any->value)
                    ->orWhere('gender_preference', $worker->gender);
            })
            ->whereDoesntHave('rejections', fn ($q) => $q->where('worker_id', $worker->id))
            ->count();

        $pendingExtensionRequestsCount = CleaningTimeWarning::query()
            ->where('booking_type', 'cleaning_booking')
            ->whereNull('worker_responded_at')
            ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $q) use ($worker): void {
                $q->where(function (Builder $bookingQuery) use ($worker): void {
                    $bookingQuery
                        ->where('worker_id', $worker->id)
                        ->orWhereHas('workerAssignments', fn (Builder $assignments) => $assignments
                            ->where('worker_id', $worker->id)
                            ->where('status', 'accepted'));
                });
            })
            ->count();

        $completedFourWeeksBookings = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereBetween('scheduled_date', [$fourWeekStart, $fourWeekEnd])
            ->with(['workerAssignments' => function (HasMany $assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id);
            }])
            ->get();

        $adminAmount = (float) $completedFourWeeksBookings->sum(fn (CleaningBooking $booking): float => $this->bookingAdminAmount($booking, $worker->id));
        $workerAmount = (float) $completedFourWeeksBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));
        $grossInvoicesAmount = (float) $completedFourWeeksBookings->sum(fn (CleaningBooking $booking): float => $this->bookingGrossAmount($booking));

        $weeklyBookingsCounts = (clone $baseQuery)
            ->whereBetween('scheduled_date', [$weekStart, $weekEnd])
            ->where('status', '!=', CleaningBookingStatus::Cancelled)
            ->get(['scheduled_date'])
            ->groupBy(fn (CleaningBooking $booking): string => $booking->scheduled_date?->toDateString() ?? '')
            ->map(fn (Collection $bookings): int => $bookings->count());

        $bookingsWeeklyChart = [];
        $cursor = $weekStart->copy();
        while ($cursor->lte($weekEnd)) {
            $dateKey = $cursor->toDateString();
            $dayKey = mb_strtolower($cursor->englishDayOfWeek);
            $bookingsWeeklyChart[] = [
                'date' => $dateKey,
                'dayKey' => $dayKey,
                'dayLabelAr' => $dayLabels[$dayKey] ?? $cursor->englishDayOfWeek,
                'bookingsCount' => (int) ($weeklyBookingsCounts->get($dateKey) ?? 0),
            ];
            $cursor->addDay();
        }

        $invoicesFourWeeksChart = [];
        for ($index = 0; $index < 4; $index++) {
            $segmentStart = $fourWeekStart->copy()->addWeeks($index);
            $segmentEnd = $segmentStart->copy()->addDays(6);
            $segmentSum = (float) $completedFourWeeksBookings
                ->filter(fn (CleaningBooking $booking): bool => $booking->scheduled_date !== null
                    && $booking->scheduled_date->betweenIncluded($segmentStart, $segmentEnd))
                ->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

            $invoicesFourWeeksChart[] = [
                'weekNumber' => $index + 1,
                'label' => 'week_'.($index + 1),
                'from' => $segmentStart->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'invoiceAmount' => round($segmentSum, 2),
                'invoiceAmountThousands' => round($segmentSum / 1000, 3),
            ];
        }

        return response()->json([
            'date' => $today->format('Y-m-d'),
            'totalBookings' => $totalBookings,
            'todayCount' => $todayCount,
            'completedCount' => $completedCount,
            'pendingCount' => $pendingCount,
            'inProgressCount' => $inProgressCount,
            'cancelledCount' => $cancelledCount,
            'totalEarnings' => $totalEarnings,
            'todayEarnings' => $todayEarnings,
            'earningsChangePercent' => $earningsChangePercent,
            'newOrdersCount' => $newOrdersCount,
            'pendingExtensionRequestsCount' => $pendingExtensionRequestsCount,
            'amountSummary' => [
                'period' => 'last_4_weeks',
                'currency' => 'SYP',
                'workerAmount' => round($workerAmount, 2),
                'adminAmount' => round($adminAmount, 2),
                'grossInvoicesAmount' => round($grossInvoicesAmount, 2),
            ],
            'bookingsWeeklyChart' => $bookingsWeeklyChart,
            'invoicesFourWeeksChart' => $invoicesFourWeeksChart,
        ]);
    }

    /**
     * @param  array<string, string>  $dayLabels
     * @return array<int, array{date: string, dayKey: string, dayLabelAr: string, bookingsCount: int}>
     */
    private function emptyBookingsWeeklyChart(Carbon $weekStart, array $dayLabels): array
    {
        $rows = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dayKey = mb_strtolower($date->englishDayOfWeek);
            $rows[] = [
                'date' => $date->toDateString(),
                'dayKey' => $dayKey,
                'dayLabelAr' => $dayLabels[$dayKey] ?? $date->englishDayOfWeek,
                'bookingsCount' => 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{weekNumber: int, label: string, from: string, to: string, invoiceAmount: float, invoiceAmountThousands: float}>
     */
    private function emptyInvoicesFourWeeksChart(Carbon $fourWeekStart): array
    {
        $rows = [];

        for ($index = 0; $index < 4; $index++) {
            $segmentStart = $fourWeekStart->copy()->addWeeks($index);
            $segmentEnd = $segmentStart->copy()->addDays(6);

            $rows[] = [
                'weekNumber' => $index + 1,
                'label' => 'week_'.($index + 1),
                'from' => $segmentStart->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'invoiceAmount' => 0.0,
                'invoiceAmountThousands' => 0.0,
            ];
        }

        return $rows;
    }

    private function bookingWorkerAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            return (float) $assignment->worker_amount;
        }

        return max(0.0, round((float) ($booking->total_price ?? 0) - (float) ($booking->admin_margin_amount ?? 0), 2));
    }

    private function bookingAdminAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            return (float) $assignment->admin_margin_amount;
        }

        return (float) ($booking->admin_margin_amount ?? 0);
    }

    private function bookingGrossAmount(CleaningBooking $booking): float
    {
        return (float) ($booking->total_price ?? 0);
    }
}

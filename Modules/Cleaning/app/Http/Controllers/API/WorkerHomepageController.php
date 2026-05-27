<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\GenderPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

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

        $baseQuery = CleaningBooking::query()->where('worker_id', $worker->id);

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

        $totalEarnings = (float) (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->sum('total_price');

        $todayEarnings = (float) (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereDate('scheduled_date', $today)
            ->sum('total_price');

        $yesterday = $today->copy()->subDay();
        $yesterdayEarnings = (float) (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereDate('scheduled_date', $yesterday)
            ->sum('total_price');

        $earningsChangePercent = match (true) {
            $yesterdayEarnings > 0 => round((($todayEarnings - $yesterdayEarnings) / $yesterdayEarnings) * 100, 1),
            $todayEarnings > 0 => 100.0,
            default => 0.0,
        };

        $newOrdersCount = CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Pending)
            ->whereDate('scheduled_date', '>=', $today)
            ->where(fn ($q) => $q->whereNull('worker_id')->orWhere('worker_id', $worker->id))
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
            ->whereHasMorph('booking', [CleaningBooking::class], fn ($q) => $q->where('worker_id', $worker->id))
            ->count();

        $completedFourWeeksBookings = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereBetween('scheduled_date', [$fourWeekStart, $fourWeekEnd])
            ->get(['scheduled_date', 'total_price', 'admin_margin_amount']);

        $grossInvoicesAmount = (float) $completedFourWeeksBookings->sum('total_price');
        $adminAmount = (float) $completedFourWeeksBookings->sum('admin_margin_amount');
        $workerAmount = (float) max(0, $grossInvoicesAmount - $adminAmount);

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
                ->sum('total_price');

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
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class WorkerStatisticsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            return response()->json([
                'range' => 'this_week',
                'summary' => [
                    'totalBookings' => 0,
                    'totalEarnings' => 0.0,
                    'confirmedCount' => 0,
                    'cancelledCount' => 0,
                    'disputedCount' => 0,
                ],
                'chart' => [],
            ]);
        }

        $today = Carbon::today();
        $startOfWeek = $today->copy()->startOfWeek();
        $endOfWeek = $today->copy()->endOfWeek();

        $bookings = CleaningBooking::query()
            ->where('worker_id', $worker->id)
            ->whereBetween('scheduled_date', [$startOfWeek, $endOfWeek])
            ->get();

        $disputes = Dispute::query()
            ->where('booking_type', 'cleaning_booking')
            ->whereHasMorph('booking', [CleaningBooking::class], static function ($query) use ($worker, $startOfWeek, $endOfWeek): void {
                $query->where('worker_id', $worker->id)
                    ->whereBetween('scheduled_date', [$startOfWeek, $endOfWeek]);
            })
            ->with('booking')
            ->get();

        $bookingsByDate = $bookings->groupBy(static fn (CleaningBooking $booking): string => (string) $booking->scheduled_date?->toDateString());
        $disputesByDate = $disputes->groupBy(static function (Dispute $dispute): ?string {
            $booking = $dispute->booking;

            if (! $booking instanceof CleaningBooking) {
                return null;
            }

            return $booking->scheduled_date?->toDateString();
        });

        $chart = [];
        $cursor = $startOfWeek->copy();

        while ($cursor->lte($endOfWeek)) {
            $dateKey = $cursor->toDateString();
            $dayBookings = $bookingsByDate->get($dateKey) ?? collect();
            $dayDisputes = $disputesByDate->get($dateKey) ?? collect();

            $chart[] = [
                'date' => $dateKey,
                'confirmed' => $dayBookings->where('status', CleaningBookingStatus::Completed)->count(),
                'cancelled' => $dayBookings->where('status', CleaningBookingStatus::Cancelled)->count(),
                'disputed' => $dayDisputes->count(),
            ];

            $cursor->addDay();
        }

        $totalBookings = $bookings->count();
        $totalEarnings = (float) $bookings
            ->where('status', CleaningBookingStatus::Completed)
            ->sum('total_price');

        $confirmedCount = $bookings->where('status', CleaningBookingStatus::Completed)->count();
        $cancelledCount = $bookings->where('status', CleaningBookingStatus::Cancelled)->count();
        $disputedCount = $disputes->count();

        return response()->json([
            'range' => 'this_week',
            'summary' => [
                'totalBookings' => $totalBookings,
                'totalEarnings' => $totalEarnings,
                'confirmedCount' => $confirmedCount,
                'cancelledCount' => $cancelledCount,
                'disputedCount' => $disputedCount,
            ],
            'chart' => $chart,
        ]);
    }
}

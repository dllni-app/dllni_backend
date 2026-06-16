<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

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
            ->where(function (Builder $query) use ($worker): void {
                $query->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments
                            ->where('worker_id', $worker->id)
                            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                    });
            })
            ->whereBetween('scheduled_date', [$startOfWeek, $endOfWeek])
            ->with(['workerAssignments' => function (HasMany $assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id);
            }])
            ->get();

        $disputes = Dispute::query()
            ->where('booking_type', 'cleaning_booking')
            ->whereHasMorph('booking', [CleaningBooking::class], static function ($query) use ($worker, $startOfWeek, $endOfWeek): void {
                $query->where(function (Builder $bookingQuery) use ($worker): void {
                    $bookingQuery
                        ->where('worker_id', $worker->id)
                        ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                            $assignments
                                ->where('worker_id', $worker->id)
                                ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                        });
                })->whereBetween('scheduled_date', [$startOfWeek, $endOfWeek]);
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
        $completedBookings = $bookings->where('status', CleaningBookingStatus::Completed)->values();
        $totalEarnings = (float) $completedBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

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
}

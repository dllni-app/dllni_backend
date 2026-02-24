<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class WorkerHomepageController
{
    public function __invoke(Request $request): JsonResponse
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            return response()->json([
                'totalBookings' => 0,
                'todayCount' => 0,
                'completedCount' => 0,
                'pendingCount' => 0,
                'inProgressCount' => 0,
                'cancelledCount' => 0,
                'totalEarnings' => 0,
                'todayEarnings' => 0,
                'newOrdersCount' => 0,
                'pendingExtensionRequestsCount' => 0,
            ]);
        }

        $baseQuery = CleaningBooking::query()->where('worker_id', $worker->id);
        $today = Carbon::today();

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
                CleaningBookingStatus::Confirmed,
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::WorkerOnTheWay,
                CleaningBookingStatus::WorkerArrived,
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

        $newOrdersCount = (clone $baseQuery)
            ->whereIn('status', [
                CleaningBookingStatus::Pending,
                CleaningBookingStatus::Confirmed,
                CleaningBookingStatus::WorkerAssigned,
            ])
            ->whereDate('scheduled_date', '>=', $today)
            ->count();

        $pendingExtensionRequestsCount = CleaningTimeWarning::query()
            ->where('booking_type', 'cleaning_booking')
            ->whereNull('worker_responded_at')
            ->whereHasMorph('booking', [CleaningBooking::class], fn ($q) => $q->where('worker_id', $worker->id))
            ->count();

        return response()->json([
            'totalBookings' => $totalBookings,
            'todayCount' => $todayCount,
            'completedCount' => $completedCount,
            'pendingCount' => $pendingCount,
            'inProgressCount' => $inProgressCount,
            'cancelledCount' => $cancelledCount,
            'totalEarnings' => $totalEarnings,
            'todayEarnings' => $todayEarnings,
            'newOrdersCount' => $newOrdersCount,
            'pendingExtensionRequestsCount' => $pendingExtensionRequestsCount,
        ]);
    }
}

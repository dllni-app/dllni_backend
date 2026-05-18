<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\GenderPreference;
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

        $today = Carbon::today();

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
        ]);
    }
}

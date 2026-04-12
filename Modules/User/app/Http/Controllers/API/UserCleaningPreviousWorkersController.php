<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class UserCleaningPreviousWorkersController
{
    public function __invoke(): JsonResponse
    {
        $userId = Auth::id();

        $history = CleaningBooking::query()
            ->where('customer_id', $userId)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereNotNull('worker_id')
            ->select([
                'worker_id',
                DB::raw('MAX(scheduled_date) as last_worked_date'),
                DB::raw('COUNT(*) as completed_jobs_count'),
            ])
            ->groupBy('worker_id')
            ->orderByDesc('last_worked_date')
            ->limit(20)
            ->get();

        $workers = Worker::query()
            ->with('user')
            ->withCount('customerRatings')
            ->whereIn('id', $history->pluck('worker_id')->all())
            ->get()
            ->keyBy('id');

        $payload = $history
            ->map(function (CleaningBooking $booking) use ($workers): ?array {
                $worker = $workers->get((int) $booking->worker_id);

                if (! $worker) {
                    return null;
                }

                return [
                    'workerId' => $worker->id,
                    'name' => $worker->first_name,
                    'avatarUrl' => $worker->getFirstMediaUrl('avatar') ?: $worker->user?->getFirstMediaUrl('primary-image') ?: null,
                    'description' => $worker->bio,
                    'ratings' => [
                        'average' => (float) $worker->average_rating,
                        'count' => (int) ($worker->customer_ratings_count ?? 0),
                    ],
                    'averageRating' => (float) $worker->average_rating,
                    'completedJobsWithUser' => (int) $booking->completed_jobs_count,
                    'lastWorkedDate' => (string) $booking->last_worked_date,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'workers' => $payload,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Enums\WorkerPreferredWorkType;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningPreviousWorkersRequest;

final class UserCleaningPreviousWorkersController
{
    public function __invoke(UserCleaningPreviousWorkersRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $propertyType = $request->validated('propertyType');

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
            ->filter(function (Worker $worker) use ($propertyType): bool {
                if (! is_string($propertyType) || $propertyType === '') {
                    return true;
                }

                return $worker->preferred_work_type?->matchesPropertyType($propertyType) ?? true;
            })
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
                    'gender' => $worker->gender,
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

<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Enums\WorkerPreferredWorkType;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerBookingScheduleConflictService;
use Modules\User\Http\Requests\UserCleaningPreviousWorkersRequest;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Throwable;

final class UserCleaningPreviousWorkersController
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerBookingScheduleConflictService $scheduleConflictService,
    ) {}

    public function __invoke(UserCleaningPreviousWorkersRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $validated = $request->validated();
        $genderPreference = $validated['genderPreference'] ?? null;
        // Historically unsupported propertyType values were ignored, not
        // rejected. Preserve that client contract while honoring supported
        // cleaning/event filters when explicitly provided.
        $propertyType = $validated['propertyType'] ?? null;
        if (! is_string($propertyType) || ! in_array($propertyType, UserCleaningOrderEstimationService::PROPERTY_TYPES, true)) {
            $propertyType = null;
        }
        $scheduledAt = $this->scheduledAt(
            $validated['scheduledDate'] ?? null,
            $validated['scheduledTime'] ?? null,
        );
        $scheduleCandidate = $this->scheduleCandidate(
            $scheduledAt,
            $validated['durationHours'] ?? null,
        );

        $assignmentHistory = CleaningBookingWorkerAssignment::query()
            ->join('cleaning_bookings', 'cleaning_booking_worker_assignments.cleaning_booking_id', '=', 'cleaning_bookings.id')
            ->where('cleaning_bookings.customer_id', $userId)
            ->where(function ($query): void {
                $query
                    ->where('cleaning_bookings.status', CleaningBookingStatus::Completed->value)
                    ->orWhere('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::Completed->value);
            })
            ->whereIn('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->whereNotNull('cleaning_booking_worker_assignments.worker_id')
            ->select([
                'cleaning_booking_worker_assignments.worker_id',
                DB::raw('MAX(cleaning_bookings.scheduled_date) as last_worked_date'),
                DB::raw('COUNT(DISTINCT cleaning_bookings.id) as completed_jobs_count'),
            ])
            ->groupBy('cleaning_booking_worker_assignments.worker_id')
            ->get();

        $legacyHistory = CleaningBooking::query()
            ->where('customer_id', $userId)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereNotNull('worker_id')
            ->whereDoesntHave('workerAssignments')
            ->select([
                'worker_id',
                DB::raw('MAX(scheduled_date) as last_worked_date'),
                DB::raw('COUNT(*) as completed_jobs_count'),
            ])
            ->groupBy('worker_id')
            ->get();

        $history = $assignmentHistory
            ->concat($legacyHistory)
            ->groupBy(static fn (object $historyRow): int => (int) $historyRow->worker_id)
            ->map(static fn ($workerHistory, int $workerId): object => (object) [
                'worker_id' => $workerId,
                'last_worked_date' => $workerHistory->max('last_worked_date'),
                'completed_jobs_count' => (int) $workerHistory->sum(static fn (object $row): int => (int) $row->completed_jobs_count),
            ])
            ->sortByDesc('last_worked_date')
            ->take(20)
            ->values();

        $workers = Worker::query()
            ->with(['user', 'deposit'])
            ->withCount('customerRatings')
            ->whereIn('id', $history->pluck('worker_id')->all())
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->whereHas('user', function ($query): void {
                $query->where('is_active', true);
            })
            ->when(is_string($propertyType), function ($query) use ($propertyType): void {
                $supportedPreferences = $propertyType === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE
                    ? [WorkerPreferredWorkType::Events->value, WorkerPreferredWorkType::Both->value]
                    : [WorkerPreferredWorkType::Cleaning->value, WorkerPreferredWorkType::Both->value];

                $query->whereIn('preferred_work_type', $supportedPreferences);
            })
            ->when(
                is_string($genderPreference) && $genderPreference !== 'any',
                fn ($query) => $query->where('gender', $genderPreference),
            )
            ->get()
            ->filter(fn (Worker $worker): bool => $this->isWorkerEligible(
                $worker,
                $scheduledAt,
                $scheduleCandidate,
            ))
            ->keyBy('id');

        $payload = $history
            ->map(function (object $historyRow) use ($workers): ?array {
                $worker = $workers->get((int) $historyRow->worker_id);

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
                    'completedJobsWithUser' => (int) $historyRow->completed_jobs_count,
                    'lastWorkedDate' => (string) $historyRow->last_worked_date,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'workers' => $payload,
        ]);
    }

    private function isWorkerEligible(
        Worker $worker,
        ?Carbon $scheduledAt,
        ?CleaningBooking $scheduleCandidate,
    ): bool {
        if (! $this->depositService->isWorkerEligibleForDispatch($worker)) {
            return false;
        }

        // A configured availability schedule is authoritative for the
        // requested start time. Workers without a saved weekly schedule keep
        // the legacy previous-worker lookup behavior.
        if (
            $scheduledAt !== null
            && is_array($worker->default_working_hours)
            && $worker->default_working_hours !== []
            && ! $worker->isAvailableAt($scheduledAt)
        ) {
            return false;
        }

        if ($scheduleCandidate !== null && $this->scheduleConflictService->hasConflict($worker, $scheduleCandidate)) {
            return false;
        }

        return true;
    }

    private function scheduledAt(mixed $scheduledDate, mixed $scheduledTime): ?Carbon
    {
        if (! is_string($scheduledDate) || ! is_string($scheduledTime)) {
            return null;
        }

        try {
            return Carbon::parse($scheduledDate.' '.mb_trim($scheduledTime), config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function scheduleCandidate(?Carbon $scheduledAt, mixed $durationHours): ?CleaningBooking
    {
        if ($scheduledAt === null) {
            return null;
        }

        $duration = is_numeric($durationHours) ? (float) $durationHours : 1.0;

        return new CleaningBooking([
            'scheduled_date' => $scheduledAt->toDateString(),
            'scheduled_time' => $scheduledAt->format('H:i'),
            'estimated_hours' => max($duration, 1.0),
            'total_hours' => max($duration, 1.0),
        ]);
    }
}

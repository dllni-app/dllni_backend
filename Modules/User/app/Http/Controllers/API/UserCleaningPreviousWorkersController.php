<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingScheduleService;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerBookingScheduleConflictService;
use Modules\User\Http\Requests\UserCleaningPreviousWorkersRequest;

final class UserCleaningPreviousWorkersController
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerBookingScheduleConflictService $scheduleConflictService,
        private readonly CleaningBookingScheduleService $scheduleService,
    ) {}

    public function __invoke(UserCleaningPreviousWorkersRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $validated = $request->validated();
        $genderPreference = $validated['genderPreference'] ?? null;
        $definitions = $this->scheduleService->definitions([
            'schedule' => $request->input('schedule'),
            'scheduledDate' => $validated['scheduledDate'] ?? null,
            'scheduledTime' => $validated['scheduledTime'] ?? null,
            'propertyDetails' => [
                'hours' => $validated['durationHours'] ?? 1,
            ],
        ]);

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
            ->when(
                is_string($genderPreference) && $genderPreference !== 'any',
                fn ($query) => $query->where('gender', $genderPreference),
            )
            ->get()
            ->filter(fn (Worker $worker): bool => $this->isWorkerEligible($worker, $definitions))
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

    /** @param array<int, array{date:string,time:string,hours:float}> $definitions */
    private function isWorkerEligible(Worker $worker, array $definitions): bool
    {
        if (! $this->depositService->isWorkerEligibleForDispatch($worker)) {
            return false;
        }

        if ($definitions !== [] && $this->scheduleConflictService->hasConflictForDefinitions($worker, $definitions)) {
            return false;
        }

        return true;
    }
}

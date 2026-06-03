<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenderPreference;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class NotifyEligibleWorkersNewOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $cleaningBookingId
    ) {
        // This job is dispatched from model observers that may run inside
        // active DB transactions. Delay queue push until commit so the booking
        // is visible to the worker process.
        $this->afterCommit();
    }

    public function handle(): void
    {
        $booking = CleaningBooking::find($this->cleaningBookingId);
        if (! $booking) {
            return;
        }

        $assignmentMode = $booking->resolvedAssignmentMode();
        $rejectedWorkerIds = $booking->rejections()->pluck('worker_id')->map(static fn (mixed $workerId): int => (int) $workerId)->all();
        $acceptedWorkerIds = $booking->workerAssignments()
            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value)
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->all();

        if ($assignmentMode === CleaningAssignmentMode::PreferredWorker->value && $booking->preferred_worker_id !== null) {
            if (in_array((int) $booking->preferred_worker_id, $acceptedWorkerIds, true)) {
                return;
            }

            $worker = Worker::query()
                ->whereKey($booking->preferred_worker_id)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('is_suspended')->orWhere('is_suspended', false);
                })
                ->whereNotIn('id', $rejectedWorkerIds)
                ->with('user')
                ->first();

            if ($worker?->user) {
                $worker->user->notify(new NewOrderRequestNotification($booking));
            }

            return;
        }

        $workers = Worker::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->when(
                $booking->gender_preference instanceof GenderPreference
                    && $booking->gender_preference !== GenderPreference::Any,
                fn ($query) => $query->where('gender', $booking->gender_preference->value)
            )
            ->whereNotIn('id', array_values(array_unique(array_merge($rejectedWorkerIds, $acceptedWorkerIds))))
            ->whereHas('zones')
            ->with('user')
            ->limit(50)
            ->get();

        foreach ($workers as $worker) {
            if ($worker->user) {
                $worker->user->notify(new NewOrderRequestNotification($booking));
            }
        }
    }
}

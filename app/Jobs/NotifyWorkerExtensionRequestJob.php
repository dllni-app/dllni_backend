<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\Cleaning\ExtensionRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class NotifyWorkerExtensionRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $timeWarningId
    ) {}

    public function handle(): void
    {
        $timeWarning = CleaningTimeWarning::with('booking')->find($this->timeWarningId);
        if (! $timeWarning) {
            return;
        }

        $booking = $timeWarning->booking;
        if (! $booking instanceof CleaningBooking) {
            return;
        }

        $workerId = $timeWarning->worker_id ?? $booking->worker_id;
        if ($workerId === null) {
            return;
        }

        $worker = Worker::query()->with('user')->find((int) $workerId);
        if (! $worker?->user) {
            return;
        }

        $worker->user->notify(new ExtensionRequestNotification($timeWarning));
    }
}

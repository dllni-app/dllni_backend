<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\Cleaning\ExtensionRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        if (! $booking instanceof CleaningBooking || ! $booking->worker_id) {
            return;
        }

        $worker = $booking->worker;
        if (! $worker?->user?->fcm_token) {
            return;
        }

        $worker->user->notify(new ExtensionRequestNotification($timeWarning));
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Dispute;
use App\Notifications\Cleaning\DisputeOpenedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Cleaning\Models\CleaningBooking;

final class NotifyWorkerDisputeOpenedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $disputeId
    ) {}

    public function handle(): void
    {
        $dispute = Dispute::with('booking')->find($this->disputeId);
        if (! $dispute) {
            return;
        }

        $booking = $dispute->booking;
        if (! $booking instanceof CleaningBooking || ! $booking->worker_id) {
            return;
        }

        $worker = $booking->worker;
        if (! $worker?->user) {
            return;
        }

        $worker->user->notify(new DisputeOpenedNotification($dispute));
    }
}

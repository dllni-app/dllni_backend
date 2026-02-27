<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Cleaning\Models\CleaningBooking;

final class NotifyEligibleWorkersNewOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $cleaningBookingId
    ) {}

    public function handle(): void
    {
        $booking = CleaningBooking::find($this->cleaningBookingId);
        if (! $booking) {
            return;
        }

        $workers = Worker::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->whereHas('zones')
            ->with('user')
            ->limit(50)
            ->get();

        foreach ($workers as $worker) {
            if ($worker->user?->fcm_token) {
                $worker->user->notify(new NewOrderRequestNotification($booking));
            }
        }
    }
}

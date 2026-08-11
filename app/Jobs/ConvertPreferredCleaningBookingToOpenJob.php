<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningPreferredWorkerFallbackService;

final class ConvertPreferredCleaningBookingToOpenJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $cleaningBookingId
    ) {
        $this->afterCommit();
    }

    public function handle(CleaningPreferredWorkerFallbackService $fallbackService): void
    {
        $booking = CleaningBooking::query()->find($this->cleaningBookingId);

        if (! $booking instanceof CleaningBooking) {
            return;
        }

        $fallbackService->convertToOpenIfEligible($booking);
    }
}

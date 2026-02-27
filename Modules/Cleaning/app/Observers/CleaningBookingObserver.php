<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingObserver
{
    public function created(CleaningBooking $booking): void
    {
        if ($booking->status !== CleaningBookingStatus::Pending) {
            return;
        }

        NotifyEligibleWorkersNewOrderJob::dispatch($booking->id);
    }
}

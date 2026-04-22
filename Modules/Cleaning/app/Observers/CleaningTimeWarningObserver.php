<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Jobs\NotifyWorkerExtensionRequestJob;
use Modules\Cleaning\Events\ServiceExtensionRequested;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningObserver
{
    public function created(CleaningTimeWarning $timeWarning): void
    {
        $booking = $timeWarning->booking;

        if ($booking instanceof CleaningBooking) {
            ServiceExtensionRequested::dispatch(
                $timeWarning->id,
                $booking->id,
                $booking->worker_id,
                $timeWarning->additional_minutes,
            );
        }

        NotifyWorkerExtensionRequestJob::dispatch($timeWarning->id);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Jobs\NotifyWorkerExtensionRequestJob;
use App\Support\Broadcast\BroadcastAfterResponse;
use Modules\Cleaning\Events\ServiceExtensionRequested;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningObserver
{
    public function created(CleaningTimeWarning $timeWarning): void
    {
        $booking = $timeWarning->booking;

        if ($booking instanceof CleaningBooking) {
            BroadcastAfterResponse::send(new ServiceExtensionRequested(
                $timeWarning->id,
                $booking->id,
                $booking->worker_id,
                $timeWarning->additional_minutes,
            ));
        }

        NotifyWorkerExtensionRequestJob::dispatch($timeWarning->id);
    }
}

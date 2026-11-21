<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\WorkerComplianceSiteEligibilityService;

final class WorkerComplianceBookingObserver
{
    public function created(CleaningBooking $booking): void
    {
        app(WorkerComplianceSiteEligibilityService::class)->captureLegacyBooking($booking);
    }

    public function updated(CleaningBooking $booking): void
    {
        if ($booking->wasChanged(['worker_id', 'status', 'started_travel_at', 'arrived_at', 'work_finished_at', 'customer_confirmed_at'])) {
            app(WorkerComplianceSiteEligibilityService::class)->captureLegacyBooking($booking);
        }
    }
}

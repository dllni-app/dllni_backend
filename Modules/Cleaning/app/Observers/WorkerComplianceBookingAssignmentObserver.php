<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\WorkerComplianceSiteEligibilityService;

final class WorkerComplianceBookingAssignmentObserver
{
    public function created(CleaningBookingWorkerAssignment $assignment): void
    {
        app(WorkerComplianceSiteEligibilityService::class)->captureBookingAssignment($assignment);
    }

    public function updated(CleaningBookingWorkerAssignment $assignment): void
    {
        if ($assignment->wasChanged(['status', 'accepted_at', 'started_travel_at', 'arrived_at', 'work_finished_at'])) {
            app(WorkerComplianceSiteEligibilityService::class)->captureBookingAssignment($assignment);
        }
    }
}

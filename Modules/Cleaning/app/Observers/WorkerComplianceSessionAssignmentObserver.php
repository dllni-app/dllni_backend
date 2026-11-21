<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\WorkerComplianceSiteEligibilityService;

final class WorkerComplianceSessionAssignmentObserver
{
    public function created(CleaningBookingSessionWorkerAssignment $assignment): void
    {
        app(WorkerComplianceSiteEligibilityService::class)->captureSessionAssignment($assignment);
    }

    public function updated(CleaningBookingSessionWorkerAssignment $assignment): void
    {
        if ($assignment->wasChanged(['status', 'accepted_at', 'started_travel_at', 'arrived_at', 'start_approved_at', 'work_finished_at', 'released_at'])) {
            app(WorkerComplianceSiteEligibilityService::class)->captureSessionAssignment($assignment);
        }
    }
}

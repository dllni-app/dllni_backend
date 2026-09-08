<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionCapabilityService
{
    public function canCustomerRescheduleEventSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        ?CarbonImmutable $clock = null,
    ): bool {
        if (
            ! $booking->isEventAssistanceBooking()
            || (int) $session->cleaning_booking_id !== (int) $booking->id
        ) {
            return false;
        }

        $status = $session->status?->value ?? (string) $session->status;
        if (! in_array($status, [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return false;
        }

        $now = $clock ?? CarbonImmutable::now(config('app.timezone'));
        $startsAt = $session->startsAt();
        if (
            $startsAt === null
            || ! $startsAt->gt($now)
            || $session->started_travel_at !== null
            || $session->arrived_at !== null
            || $session->customer_confirmed_at !== null
            || $session->work_started_at !== null
            || $session->work_finished_at !== null
        ) {
            return false;
        }

        $session->loadMissing('workerAssignments');

        return ! $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && (
                    $assignment->started_travel_at !== null
                    || $assignment->arrived_at !== null
                    || $assignment->start_approved_at !== null
                    || $assignment->work_started_at !== null
                ),
        );
    }

    public function canCustomerChangeEventSessionDuration(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        ?CarbonImmutable $clock = null,
    ): bool {
        if (! $this->canCustomerRescheduleEventSession($booking, $session, $clock)) {
            return false;
        }

        $session->loadMissing('workerAssignments');

        return ! $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Read-only team progress for UI; never drives booking lifecycle decisions. */
final class CleaningBookingTeamPresentation
{
    public function currentSession(CleaningBooking $booking): ?CleaningBookingSession
    {
        if (! $booking->isV2OperationalGroup()) {
            return null;
        }

        return $booking->sessions()
            ->whereNotIn('status', CleaningBookingSessionStatus::terminalValues())
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();
    }

    /** @return array{required:int,accepted:int,remaining:int,startApproved:int} */
    public function counts(CleaningBooking $booking): array
    {
        $session = $this->currentSession($booking);
        if ($session === null) {
            $required = max(1, (int) ($booking->number_of_workers ?? 1));
            $accepted = $booking->acceptedWorkerCount();
            $approved = $booking->startApprovedWorkerCount();
        } else {
            $required = max(1, (int) ($session->required_workers ?? $booking->number_of_workers ?? 1));
            $assignments = $session->workerAssignments()
                ->whereNull('released_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            $accepted = (clone $assignments)->distinct()->count('worker_id');
            $approved = (clone $assignments)->whereNotNull('start_approved_at')->distinct()->count('worker_id');
        }

        return [
            'required' => $required,
            'accepted' => $accepted,
            'remaining' => max(0, $required - $accepted),
            'startApproved' => $approved,
        ];
    }
}

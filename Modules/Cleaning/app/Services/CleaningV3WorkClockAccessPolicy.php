<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Central V3 visit clock authorization for all reads and command mutations.
 *
 * Approval is restricted to the current assigned supervisor, or in the
 * absence of a supervisor to the single worker / current team leader.
 */
final class CleaningV3WorkClockAccessPolicy
{
    public function canRead(?User $actor, CleaningBooking $booking, CleaningBookingSession $visit): bool
    {
        if (! $this->validVisitActor($actor, $booking, $visit)) {
            return false;
        }

        return $this->isCustomer($actor, $booking)
            || $this->isAcceptedWorker($actor, $visit)
            || $this->isCurrentSupervisor($actor, $booking);
    }

    public function isCustomer(?User $actor, CleaningBooking $booking): bool
    {
        return $actor instanceof User && $actor->is_active
            && (int) $actor->id === (int) $booking->customer_id;
    }

    public function canDecide(?User $actor, CleaningBooking $booking, CleaningBookingSession $visit): bool
    {
        if (! $this->validVisitActor($actor, $booking, $visit)) {
            return false;
        }

        if ($this->requiresSupervisor($booking, $visit)) {
            return $this->isCurrentSupervisor($actor, $booking);
        }

        if ((int) $visit->requiredWorkerCount() === 1) {
            return $this->isAcceptedWorker($actor, $visit, true);
        }

        $leaderId = $visit->workerAssignments()
            ->whereNull('released_at')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->value('worker_id');

        return $leaderId !== null && $actor?->worker instanceof Worker
            && (int) $actor->worker->id === (int) $leaderId
            && $this->isAcceptedWorker($actor, $visit, true);
    }

    private function validVisitActor(?User $actor, CleaningBooking $booking, CleaningBookingSession $visit): bool
    {
        return $actor instanceof User && $actor->is_active
            && (int) $visit->cleaning_booking_id === (int) $booking->id
            && (string) ($booking->contract_version ?? '') === 'v3';
    }

    private function isAcceptedWorker(?User $actor, CleaningBookingSession $visit, bool $activeOnly = false): bool
    {
        $worker = $actor?->worker;
        if (! $worker instanceof Worker) {
            return false;
        }

        return $visit->workerAssignments()->where('worker_id', $worker->id)
            ->whereNull('released_at')
            ->whereIn('status', $activeOnly
                ? CleaningBookingWorkerAssignmentStatus::activeValues()
                : CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
    }

    private function isCurrentSupervisor(?User $actor, CleaningBooking $booking): bool
    {
        return $actor instanceof User
            && $actor->is_active
            && $actor->hasRole(SupervisedTeamService::ROLE)
            && app(SupervisedTeamService::class)->assignedQuery($actor)
                ->whereKey($booking->getKey())->exists();
    }

    private function requiresSupervisor(CleaningBooking $booking, CleaningBookingSession $visit): bool
    {
        if (CleaningV3OperationalAdmissionService::admitted($booking)) {
            return $visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR;
        }
        return (string) $booking->booking_kind === SupervisedTeamService::KIND
            || (string) $visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || DB::table('cleaning_supervisor_assignments')
                ->where('cleaning_booking_id', $booking->id)
                ->where('active_slot', 1)
                ->whereNull('released_at')
                ->exists();
    }
}

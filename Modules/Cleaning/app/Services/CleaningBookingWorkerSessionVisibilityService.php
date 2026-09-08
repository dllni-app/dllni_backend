<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingWorkerSessionVisibilityService
{
    public function __construct(
        private readonly CleaningBookingSessionAcceptanceService $acceptanceService,
    ) {}

    public function canViewBooking(CleaningBooking $booking, Worker $worker): bool
    {
        $sessions = $this->bookingSessions($booking);

        if ($sessions->isEmpty()) {
            return $this->canViewLegacyBooking($booking, $worker);
        }

        if ($this->visibleSessionsFrom($booking, $worker, $sessions)->isNotEmpty()) {
            return true;
        }

        // A released/replaced worker may refetch the parent after a realtime
        // update so the client can remove stale session state. The historical
        // assignment is a legitimate booking relationship, but it does not make
        // the released session visible again.
        return $this->hasSessionAssignmentHistory($sessions, $worker);
    }

    public function canViewSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): bool {
        if ((int) $session->cleaning_booking_id !== (int) $booking->id) {
            return false;
        }

        return $this->visibleSessions($booking, $worker)
            ->contains(static fn (CleaningBookingSession $visible): bool => (int) $visible->id === (int) $session->id);
    }

    /** @return Collection<int, CleaningBookingSession> */
    public function visibleSessions(CleaningBooking $booking, Worker $worker): Collection
    {
        return $this->visibleSessionsFrom($booking, $worker, $this->bookingSessions($booking));
    }

    /** @return Collection<int, CleaningBookingSession> */
    private function bookingSessions(CleaningBooking $booking): Collection
    {
        return CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->with('workerAssignments')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @param  Collection<int, CleaningBookingSession>  $sessions
     * @return Collection<int, CleaningBookingSession>
     */
    private function visibleSessionsFrom(
        CleaningBooking $booking,
        Worker $worker,
        Collection $sessions,
    ): Collection {
        if ($sessions->isEmpty()) {
            return collect();
        }

        $assignedOrHistorical = $sessions
            ->filter(fn (CleaningBookingSession $session): bool => $this->hasAcceptedAssignment($session, $worker))
            ->values();

        // Once the worker has participated in this booking, assignment rows are
        // the authoritative operational scope. In particular, a released event
        // day must not become visible again simply because a seat reopened.
        if ($this->hasSessionAssignmentHistory($sessions, $worker)) {
            return $assignedOrHistorical;
        }

        $isMultiDayEvent = (string) $booking->property_type === 'event_assistance'
            && $sessions->count() > 1;

        if ($isMultiDayEvent) {
            if (! $this->acceptanceService->canAcceptAllAvailableSessions($booking, $worker)) {
                return collect();
            }

            return $sessions
                ->filter(fn (CleaningBookingSession $session): bool => $this->isOpenForAcceptance($session))
                ->values();
        }

        return $sessions
            ->filter(function (CleaningBookingSession $session) use ($booking, $worker): bool {
                if ($this->hasAcceptedAssignment($session, $worker)) {
                    return true;
                }

                return $this->acceptanceService->canAcceptSession($booking, $session, $worker);
            })
            ->values();
    }

    private function hasAcceptedAssignment(CleaningBookingSession $session, Worker $worker): bool
    {
        return $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => (int) $assignment->worker_id === (int) $worker->id
                && $assignment->isAccepted(),
        );
    }

    /** @param Collection<int, CleaningBookingSession> $sessions */
    private function hasSessionAssignmentHistory(Collection $sessions, Worker $worker): bool
    {
        return $sessions->contains(
            static fn (CleaningBookingSession $session): bool => $session->workerAssignments->contains(
                static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => (int) $assignment->worker_id === (int) $worker->id,
            ),
        );
    }

    private function isOpenForAcceptance(CleaningBookingSession $session): bool
    {
        $status = $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;

        return in_array($status, [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)
            && ! $session->isTerminal()
            && $session->remainingWorkerCount() > 0;
    }

    private function canViewLegacyBooking(CleaningBooking $booking, Worker $worker): bool
    {
        if ((int) ($booking->worker_id ?? 0) === (int) $worker->id) {
            return true;
        }

        if ((int) ($booking->preferred_worker_id ?? 0) === (int) $worker->id) {
            return true;
        }

        return $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningBookingSessionStatusService
{
    public function refreshParent(CleaningBooking $booking): CleaningBooking
    {
        $booking->loadMissing(['sessions.workerAssignments', 'workerAssignments.sessionAssignments.session']);
        $sessions = $booking->sessions->sortBy('sequence')->values();

        if ($sessions->isEmpty()) {
            return $booking;
        }

        $this->refreshParentWorkerAssignments($booking);

        $nonCancelled = $sessions->reject(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Cancelled);
        $completedCount = $nonCancelled->filter(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Completed)->count();
        $status = $this->aggregateStatus($booking, $sessions, $nonCancelled, $completedCount);
        $first = $sessions->first();
        $active = $sessions->first(fn (CleaningBookingSession $session): bool => ! in_array($session->status, [CleaningBookingSessionStatus::Completed, CleaningBookingSessionStatus::Cancelled], true));
        $latestCompleted = $sessions
            ->filter(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Completed)
            ->sortByDesc('work_finished_at')
            ->first();

        $booking->forceFill([
            'status' => $status,
            'scheduled_date' => $first?->scheduled_date,
            'scheduled_time' => $first?->scheduled_time,
            'total_hours' => round((float) $sessions->reject(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Cancelled)->sum('duration_hours'), 2),
            'started_travel_at' => $active?->started_travel_at,
            'arrived_at' => $active?->arrived_at,
            'work_started_at' => $active?->work_started_at,
            'work_finished_at' => $status === CleaningBookingStatus::Completed ? $latestCompleted?->work_finished_at : null,
            'customer_confirmed_at' => $active?->customer_confirmed_at ?? ($status === CleaningBookingStatus::Completed ? $latestCompleted?->customer_confirmed_at : null),
            'cancelled_at' => $status === CleaningBookingStatus::Cancelled ? ($booking->cancelled_at ?? now()) : null,
        ])->saveQuietly();

        return $booking->fresh(['sessions.workerAssignments', 'workerAssignments']);
    }

    /** @param Collection<int, CleaningBookingSession> $sessions @param Collection<int, CleaningBookingSession> $nonCancelled */
    private function aggregateStatus(CleaningBooking $booking, Collection $sessions, Collection $nonCancelled, int $completedCount): CleaningBookingStatus
    {
        if ($nonCancelled->isEmpty()) {
            return CleaningBookingStatus::Cancelled;
        }

        if ($nonCancelled->contains(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::UnderDispute)) {
            return CleaningBookingStatus::UnderDispute;
        }
        if ($nonCancelled->contains(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::TimeExtensionRequested)) {
            return CleaningBookingStatus::TimeExtensionRequested;
        }
        if ($nonCancelled->contains(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::AwaitingCustomerCompletion)) {
            return CleaningBookingStatus::AwaitingCustomerCompletion;
        }
        if ($nonCancelled->contains(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::InProgress)) {
            return CleaningBookingStatus::InProgress;
        }
        if ($nonCancelled->contains(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation)) {
            return CleaningBookingStatus::AwaitingWorkerStartConfirmation;
        }
        if ($nonCancelled->contains(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::AwaitingStartVerification)) {
            return CleaningBookingStatus::AwaitingStartVerification;
        }
        if ($completedCount === $nonCancelled->count()) {
            return CleaningBookingStatus::Completed;
        }
        if ($completedCount > 0) {
            return CleaningBookingStatus::PartiallyCompleted;
        }

        return $booking->isTeamFulfilled()
            ? CleaningBookingStatus::WorkerAssigned
            : CleaningBookingStatus::Pending;
    }

    private function refreshParentWorkerAssignments(CleaningBooking $booking): void
    {
        foreach ($booking->workerAssignments as $parent) {
            $sessionAssignments = $parent->sessionAssignments
                ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->session?->status !== CleaningBookingSessionStatus::Cancelled);

            if ($sessionAssignments->isEmpty()) {
                continue;
            }

            $status = match (true) {
                $sessionAssignments->every(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::Completed->value)
                    => CleaningBookingWorkerAssignmentStatus::Completed,
                $sessionAssignments->contains(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value)
                    => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
                $sessionAssignments->contains(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value)
                    => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                $sessionAssignments->contains(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::InProgress->value)
                    => CleaningBookingWorkerAssignmentStatus::InProgress,
                $sessionAssignments->contains(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::StartApproved->value)
                    => CleaningBookingWorkerAssignmentStatus::StartApproved,
                $sessionAssignments->contains(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value)
                    => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
                default => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
            };

            $parent->forceFill(['status' => $status])->saveQuietly();
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionWorkerChangeService
{
    public function __construct(
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    /**
     * Release only the explicitly selected worker assignments from future event
     * sessions. The whole selection is validated before any assignment changes,
     * so a stale/invalid session never produces a silent partial release.
     *
     * @param  array<int,array{sessionId:int,workerIds:array<int,int>}>  $changes
     * @return array{releasedAssignments:array<int,array{sessionId:int,workerId:int}>,changedSessionIds:array<int,int>}
     */
    public function releaseSelectedFutureAssignments(
        CleaningBooking $booking,
        int $customerId,
        array $changes,
        string $reason,
    ): array {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }
        if ((string) $booking->property_type !== 'event_assistance') {
            throw new InvalidArgumentException('Worker changes are currently available only for event assistance bookings.');
        }

        $normalizedReason = mb_trim($reason);
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('Worker change reason is required.');
        }

        $normalizedChanges = $this->normalizeChanges($changes);
        if ($normalizedChanges === []) {
            throw new InvalidArgumentException('Select at least one future session worker to change.');
        }

        $released = DB::transaction(function () use (
            $booking,
            $customerId,
            $normalizedChanges,
            $normalizedReason,
        ): array {
            $lockedBooking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedBooking->customer_id !== $customerId) {
                abort(403, 'Booking belongs to another customer.');
            }
            if ((string) $lockedBooking->property_type !== 'event_assistance') {
                throw new InvalidArgumentException('Worker changes are currently available only for event assistance bookings.');
            }

            $sessionIds = array_keys($normalizedChanges);
            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->whereIn('id', $sessionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sessions->count() !== count($sessionIds)) {
                throw new InvalidArgumentException('One or more selected sessions do not belong to this booking.');
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            $assignmentsBySession = [];

            // Full preflight first. No assignment is released until every selected
            // session and worker is still safe to change under the locked state.
            foreach ($sessionIds as $sessionId) {
                /** @var CleaningBookingSession $session */
                $session = $sessions->get($sessionId);
                $this->assertFutureSessionCanChangeWorkers($session, $now);

                $workerIds = $normalizedChanges[$sessionId];
                $assignments = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereIn('worker_id', $workerIds)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($assignments->count() !== count($workerIds)) {
                    throw new InvalidArgumentException(
                        'One or more selected workers are no longer actively assigned to the selected session.'
                    );
                }

                foreach ($assignments as $assignment) {
                    if (
                        $assignment->started_travel_at !== null
                        || $assignment->arrived_at !== null
                        || $assignment->start_approved_at !== null
                        || $assignment->work_started_at !== null
                    ) {
                        throw new InvalidArgumentException(
                            'A selected worker already started travel or execution and can no longer be changed.'
                        );
                    }
                }

                $assignmentsBySession[$sessionId] = $assignments;
            }

            $releasedAssignments = [];
            foreach ($sessionIds as $sessionId) {
                /** @var CleaningBookingSession $session */
                $session = $sessions->get($sessionId);
                $assignments = $assignmentsBySession[$sessionId];

                foreach ($assignments as $assignment) {
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => now(),
                        'released_reason' => 'Customer requested worker replacement: '.$normalizedReason,
                    ])->save();

                    $releasedAssignments[] = [
                        'sessionId' => (int) $session->id,
                        'workerId' => (int) $assignment->worker_id,
                    ];
                }

                $acceptedCount = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                    ->count();
                $requiredCount = $session->requiredWorkerCount();
                $coverage = match (true) {
                    $acceptedCount <= 0 => CleaningBookingSessionCoverageStatus::Searching,
                    $acceptedCount < $requiredCount => CleaningBookingSessionCoverageStatus::PartiallyCovered,
                    default => CleaningBookingSessionCoverageStatus::FullyCovered,
                };

                $session->forceFill([
                    'coverage_status' => $coverage,
                    'status' => $acceptedCount > 0
                        ? CleaningBookingSessionStatus::WorkerAssigned
                        : CleaningBookingSessionStatus::Scheduled,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
            }

            return [
                'releasedAssignments' => $releasedAssignments,
                'changedSessionIds' => array_map('intval', $sessionIds),
            ];
        }, 3);

        $freshBooking = $this->parentState->refresh($booking)->fresh(['customer']) ?? $booking;
        foreach ($released['releasedAssignments'] as $item) {
            $this->notifications->notifyWorkerById(
                booking: $freshBooking,
                workerId: $item['workerId'],
                canonicalType: 'cleaning.booking.worker_changed',
                action: 'customer_requested_session_worker_change',
                actorRole: 'customer',
                occurredAt: now()->toIso8601String(),
                extraData: [
                    'sessionId' => $item['sessionId'],
                    'reason' => $normalizedReason,
                ],
                templateContext: [
                    'sessionId' => $item['sessionId'],
                    'reason' => $normalizedReason,
                ],
            );
        }

        return $released;
    }

    /**
     * @param  array<int,array{sessionId:int,workerIds:array<int,int>}>  $changes
     * @return array<int,array<int,int>>
     */
    private function normalizeChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $change) {
            $sessionId = (int) ($change['sessionId'] ?? 0);
            $workerIds = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $change['workerIds'] ?? []),
                static fn (int $id): bool => $id > 0,
            )));

            if ($sessionId <= 0 || $workerIds === []) {
                throw new InvalidArgumentException('Every selected session must include at least one worker to change.');
            }
            if (isset($normalized[$sessionId])) {
                throw new InvalidArgumentException('Each selected session may appear only once in a worker-change request.');
            }

            $normalized[$sessionId] = $workerIds;
        }

        ksort($normalized);

        return $normalized;
    }

    private function assertFutureSessionCanChangeWorkers(
        CleaningBookingSession $session,
        CarbonImmutable $now,
    ): void {
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Completed, cancelled, or skipped sessions cannot change workers.');
        }
        if (! in_array($session->status, [
            CleaningBookingSessionStatus::Scheduled,
            CleaningBookingSessionStatus::WorkerAssigned,
        ], true)) {
            throw new InvalidArgumentException('This session is already in execution preparation and cannot change workers.');
        }
        if ($session->work_started_at !== null) {
            throw new InvalidArgumentException('A session that already started work cannot change workers.');
        }

        $startsAt = $session->startsAt();
        if ($startsAt === null || ! $startsAt->gt($now)) {
            throw new InvalidArgumentException('Only future event sessions can change workers.');
        }
    }
}

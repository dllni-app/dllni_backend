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

final class CleaningBookingSessionAttendanceService
{
    public const ACTION_WAIT = 'wait';

    public const ACTION_REPLACE = 'replace';

    public const ACTION_CANCEL = 'cancel';

    public function __construct(
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    /** @param array<int, int> $workerIds */
    public function handle(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        array $workerIds,
        string $action,
        ?string $note = null,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        $action = mb_strtolower(mb_trim($action));
        if (! in_array($action, [self::ACTION_WAIT, self::ACTION_REPLACE, self::ACTION_CANCEL], true)) {
            throw new InvalidArgumentException('Unsupported attendance action.');
        }

        $workerIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $workerId): int => (int) $workerId, $workerIds),
            static fn (int $workerId): bool => $workerId > 0,
        )));
        if ($workerIds === []) {
            throw new InvalidArgumentException('Select at least one worker for the attendance action.');
        }

        $note = $this->nullableTrimmed($note);
        $affectedWorkerIds = [];

        $updated = DB::transaction(function () use (
            $booking,
            $session,
            $workerIds,
            $action,
            $note,
            &$affectedWorkerIds,
        ): CleaningBookingSession {
            $locked = CleaningBookingSession::query()
                ->whereKey($session->id)
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof CleaningBookingSession) {
                throw new InvalidArgumentException('Session does not belong to this booking.');
            }
            if ((string) $locked->session_type !== CleaningBookingSession::TYPE_RECURRING_CLEANING) {
                throw new InvalidArgumentException('Attendance escalation is available only for recurring cleaning visits.');
            }
            if ($locked->isTerminal() || ! in_array($locked->status, [
                CleaningBookingSessionStatus::Scheduled,
                CleaningBookingSessionStatus::WorkerAssigned,
            ], true)) {
                throw new InvalidArgumentException('This visit can no longer use late or no-travel options.');
            }
            if ($locked->work_started_at !== null) {
                throw new InvalidArgumentException('Attendance escalation is unavailable after work starts.');
            }

            $startsAt = $locked->startsAt();
            if (! $startsAt instanceof CarbonImmutable) {
                throw new InvalidArgumentException('Visit start time is unavailable.');
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            $lateGrace = max(0, (int) config('cleaning_attendance.late_grace_minutes', 15));
            $noTravelGrace = max($lateGrace, (int) config('cleaning_attendance.no_travel_grace_minutes', 30));
            $requiredGrace = $action === self::ACTION_WAIT ? $lateGrace : $noTravelGrace;
            if ($now->lt($startsAt->addMinutes($requiredGrace))) {
                throw new InvalidArgumentException(
                    $action === self::ACTION_WAIT
                        ? 'The late grace period has not elapsed yet.'
                        : 'The no-travel grace period has not elapsed yet.'
                );
            }

            $selected = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('worker_id', $workerIds)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($selected->count() !== count($workerIds)) {
                throw new InvalidArgumentException('One or more selected workers are no longer active on this visit.');
            }
            foreach ($selected as $assignment) {
                if ($assignment->started_travel_at !== null || $assignment->arrived_at !== null || $assignment->work_started_at !== null) {
                    throw new InvalidArgumentException('A selected worker already started travel or execution.');
                }
            }

            $reportedAt = now();
            $affectedWorkerIds = $workerIds;

            if ($action === self::ACTION_WAIT) {
                $isNoTravelIncident = $now->gte($startsAt->addMinutes($noTravelGrace));
                $hasMeaningfulTransition = false;

                foreach ($selected as $assignment) {
                    $lateWasMissing = $assignment->late_reported_at === null;
                    $noTravelWasMissing = $isNoTravelIncident && $assignment->no_travel_reported_at === null;
                    $actionChanged = $assignment->attendance_action !== self::ACTION_WAIT;
                    $hasMeaningfulTransition = $hasMeaningfulTransition
                        || $lateWasMissing
                        || $noTravelWasMissing
                        || $actionChanged;

                    $assignment->forceFill([
                        'late_reported_at' => $assignment->late_reported_at ?? $reportedAt,
                        'no_travel_reported_at' => $isNoTravelIncident
                            ? ($assignment->no_travel_reported_at ?? $reportedAt)
                            : $assignment->no_travel_reported_at,
                        'attendance_action' => self::ACTION_WAIT,
                        'attendance_note' => $note ?? $assignment->attendance_note,
                    ]);

                    if ($assignment->isDirty()) {
                        $assignment->save();
                    }
                }

                // Repeating the same wait decision is intentionally idempotent. The
                // existing incident remains the source of truth and workers are not
                // re-notified unless the attendance state actually progressed (for
                // example, late -> no-travel).
                if (! $hasMeaningfulTransition) {
                    $affectedWorkerIds = [];
                }

                return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
            }

            if ($action === self::ACTION_REPLACE) {
                foreach ($selected as $assignment) {
                    $assignment->forceFill([
                        'late_reported_at' => $assignment->late_reported_at ?? $reportedAt,
                        'no_travel_reported_at' => $assignment->no_travel_reported_at ?? $reportedAt,
                        'attendance_action' => self::ACTION_REPLACE,
                        'attendance_resolved_at' => $reportedAt,
                        'attendance_note' => $note,
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => $reportedAt,
                        'released_reason' => 'Customer reported no travel and requested replacement.',
                    ])->save();
                }

                $this->syncCoverageAfterRelease($locked);

                return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
            }

            $allActive = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $affectedWorkerIds = $allActive->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
                ->filter(static fn (int $workerId): bool => $workerId > 0)
                ->unique()
                ->values()
                ->all();
            $selectedWorkerLookup = array_fill_keys($workerIds, true);

            foreach ($allActive as $assignment) {
                $isReportedWorker = isset($selectedWorkerLookup[(int) $assignment->worker_id]);
                $assignment->forceFill([
                    'late_reported_at' => $isReportedWorker ? ($assignment->late_reported_at ?? $reportedAt) : $assignment->late_reported_at,
                    'no_travel_reported_at' => $isReportedWorker ? ($assignment->no_travel_reported_at ?? $reportedAt) : $assignment->no_travel_reported_at,
                    'attendance_action' => $isReportedWorker ? self::ACTION_CANCEL : $assignment->attendance_action,
                    'attendance_resolved_at' => $isReportedWorker ? $reportedAt : $assignment->attendance_resolved_at,
                    'attendance_note' => $isReportedWorker ? $note : $assignment->attendance_note,
                    'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                    'released_at' => $reportedAt,
                    'released_reason' => $isReportedWorker
                        ? 'Customer cancelled visit after reported worker no-travel.'
                        : 'Visit cancelled because required worker did not start travel.',
                ])->save();
            }

            $locked->forceFill([
                'status' => CleaningBookingSessionStatus::Cancelled,
                'cancellation_fee' => 0,
                'cancelled_at' => $reportedAt,
                'cancellation_reason' => 'Worker no-travel'.($note !== null ? ': '.$note : ''),
                'cancelled_by_role' => 'customer',
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();
            $this->financialAggregation->sync($booking);

            return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
        }, 3);

        $this->parentState->refresh($booking);
        $freshBooking = $booking->fresh(['customer']) ?? $booking;
        foreach ($affectedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $freshBooking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: match ($action) {
                    self::ACTION_WAIT => 'customer_reported_worker_late',
                    self::ACTION_REPLACE => 'customer_reported_no_travel_replacement',
                    default => 'customer_cancelled_session_after_no_travel',
                },
                actorRole: 'customer',
                occurredAt: now()->toIso8601String(),
                extraData: [
                    'sessionId' => (int) $updated->id,
                    'attendanceAction' => $action,
                    'attendanceNote' => $note,
                ],
            );
        }

        return $updated->fresh(['workerAssignments.worker.user']) ?? $updated;
    }

    private function syncCoverageAfterRelease(CleaningBookingSession $session): void
    {
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

    private function nullableTrimmed(?string $value): ?string
    {
        $value = $value !== null ? mb_trim($value) : null;

        return $value === '' ? null : $value;
    }
}

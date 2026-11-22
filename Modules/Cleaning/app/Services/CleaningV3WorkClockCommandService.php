<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Visit-scoped, transactional V3 clock transitions.
 *
 * An approved start must come from the existing verified visit lifecycle,
 * never an independent customer clock endpoint. No money is charged here.
 * All client commands require exact-visit authorization and optimistic
 * revision checks under a database row lock. Idempotent replays are accepted
 * only for the same command kind and actor where the actor is recorded.
 */
final class CleaningV3WorkClockCommandService
{
    public function __construct(
        private readonly CleaningV3WorkClockAccessPolicy $access,
        private readonly CleaningV3WorkClockProjectionService $clock,
        private readonly WorkerBookingScheduleConflictService $scheduleConflicts,
    ) {}

    /**
     * Called ONLY inside an approved work-start lifecycle after all required
     * assigned workers have actually started work.
     *
     * @return array<string, mixed>
     */
    public function initializeApprovedVisit(int $bookingId, int $sessionId): array
    {
        return DB::transaction(function () use ($bookingId, $sessionId): array {
            [$booking, $visit] = $this->lockVisit($bookingId, $sessionId);
            $status = $this->status($visit);
            if ($status !== CleaningBookingSessionStatus::InProgress->value
                || $visit->work_started_at === null) {
                $this->fail('CLOCK_START_REQUIRES_APPROVED_WORK');
            }

            $alreadyStarted = DB::table('cleaning_work_intervals')
                ->where('cleaning_booking_session_id', $visit->id)->exists();
            if ($alreadyStarted) {
                return $this->clock->project($visit);
            }

            $startedWorkers = $visit->workerAssignments()
                ->whereNull('released_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->whereNotNull('work_started_at')->count();
            if ($startedWorkers < $visit->requiredWorkerCount()) {
                $this->fail('CLOCK_START_REQUIRES_APPROVED_WORK');
            }

            $at = CarbonImmutable::now('UTC');
            $revision = (int) $visit->clock_revision + 1;
            DB::table('cleaning_work_intervals')->insert([
                'cleaning_booking_session_id' => $visit->id,
                'active_session_id' => $visit->id,
                'start_command_key' => 'approved-lifecycle-start-'.$visit->id,
                'opened_clock_revision' => $revision,
                'started_at' => $at->toDateTimeString(),
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $visit->forceFill(['clock_revision' => $revision])->save();

            return $this->clock->project($visit->fresh(), $at);
        }, 3);
    }

    /**
     * Called only when the existing worker completion workflow has verified
     * that every required worker has finished this visit. The subsequent
     * visit status update to awaiting_customer_completion happens in the
     * same outer transaction. No financial action or payment flag is written.
     */
    public function stopForApprovedCompletion(int $bookingId, int $sessionId): void
    {
        DB::transaction(function () use ($bookingId, $sessionId): void {
            [$booking, $visit] = $this->lockVisit($bookingId, $sessionId);
            $now = CarbonImmutable::now('UTC');
            $clock = $this->clock->project($visit, $now);

            if ($clock['state'] === 'paused' || $clock['state'] === 'not_running') {
                return;
            }
            if ($clock['state'] !== 'running') {
                $this->fail('CLOCK_NOT_RUNNING');
            }

            $interval = DB::table('cleaning_work_intervals')
                ->where('active_session_id', $visit->id)->lockForUpdate()->first();
            if ($interval === null || CarbonImmutable::parse($interval->started_at, 'UTC') >= $now) {
                $this->fail('CLOCK_INTERVAL_NOT_READY');
            }

            $nextRevision = (int) $visit->clock_revision + 1;
            DB::table('cleaning_work_intervals')->where('id', $interval->id)->update([
                'active_session_id' => null,
                'end_command_key' => 'lifecycle-finish-'.$visit->id.'-'.$nextRevision,
                'closed_clock_revision' => $nextRevision,
                'ended_at' => $now->toDateTimeString(),
                'updated_at' => $now,
            ]);

            // If completion wins the race against a pending pause, the pending
            // approval is invalidated within this same locked transaction.
            DB::table('cleaning_work_pause_requests')
                ->where('cleaning_booking_session_id', $visit->id)
                ->where('status', 'pending')
                ->update([
                    'pending_session_id' => null,
                    'status' => 'invalidated',
                    'decision_command_key' => 'lifecycle-finish-'.$visit->id.'-'.$nextRevision,
                    'decided_at' => $now,
                    'updated_at' => $now,
                ]);

            $visit->forceFill(['clock_revision' => $nextRevision])->save();
        }, 3);
    }

    /**
     * A rejected completion is an authorized customer decision from the
     * existing visit lifecycle. Reopen the same visit's clock, without
     * resetting its active-time total, only when the crew can still finish
     * inside the visit window without conflicting assignments.
     */
    public function resumeAfterRejectedCompletion(int $bookingId, int $sessionId): void
    {
        DB::transaction(function () use ($bookingId, $sessionId): void {
            [$booking, $visit] = $this->lockVisit($bookingId, $sessionId);
            if ($this->status($visit) !== CleaningBookingSessionStatus::AwaitingCustomerCompletion->value) {
                $this->fail('CLOCK_NOT_AWAITING_COMPLETION');
            }

            $at = CarbonImmutable::now('UTC');
            $clock = $this->clock->project($visit, $at);
            if ($clock['state'] !== 'not_running' || $clock['pausePending']
                || (int) $clock['activeSeconds'] < 1) {
                $this->fail('CLOCK_NOT_READY_TO_RESUME');
            }

            $this->assertCrewCanResume($booking, $visit, $at, (int) $clock['activeSeconds']);
            $revision = (int) $visit->clock_revision + 1;
            DB::table('cleaning_work_intervals')->insert([
                'cleaning_booking_session_id' => $visit->id,
                'active_session_id' => $visit->id,
                'start_command_key' => 'lifecycle-rejected-'.$visit->id.'-'.$revision,
                'opened_clock_revision' => $revision,
                'started_at' => $at->toDateTimeString(),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $visit->forceFill(['clock_revision' => $revision])->save();
        }, 3);
    }

    /**
     * @return array{clock:array<string,mixed>,replayed:bool,pauseRequestId:?int}
     */
    public function execute(
        int $bookingId,
        int $sessionId,
        User $actor,
        string $action,
        string $commandKey,
        int $expectedRevision,
        ?int $pauseRequestId = null,
    ): array {
        if (! in_array($action, ['request', 'withdraw', 'approve', 'reject', 'resume'], true)
            || preg_match('/^[a-zA-Z0-9_.:-]{1,96}$/D', $commandKey) !== 1
            || $expectedRevision < 0 || $expectedRevision > 4294967294) {
            $this->fail('INVALID_CLOCK_COMMAND', 422);
        }
        if (in_array($action, ['withdraw', 'approve', 'reject'], true)
            && ($pauseRequestId === null || $pauseRequestId < 1)) {
            $this->fail('PAUSE_REQUEST_ID_REQUIRED', 422);
        }

        return DB::transaction(function () use (
            $bookingId, $sessionId, $actor, $action, $commandKey, $expectedRevision, $pauseRequestId,
        ): array {
            [$booking, $visit] = $this->lockVisit($bookingId, $sessionId);
            $customerAction = in_array($action, ['request', 'withdraw'], true);
            $authorized = $customerAction
                ? $this->access->isCustomer($actor, $booking)
                : $this->access->canDecide($actor, $booking, $visit);
            abort_unless($authorized, 404);

            $replayedId = $this->replayId($visit, $actor, $action, $commandKey, $pauseRequestId);
            if ($replayedId !== null) {
                return $this->result($visit, true, $replayedId);
            }
            if ($this->keyUsedForAnotherCommand($visit, $commandKey)) {
                $this->fail('CLOCK_COMMAND_KEY_CONFLICT');
            }
            if ((int) $visit->clock_revision !== $expectedRevision) {
                $this->fail('STALE_CLOCK_REVISION');
            }
            if (in_array($this->status($visit), CleaningBookingSessionStatus::terminalValues(), true)) {
                $this->fail('V3_VISIT_TERMINAL');
            }

            $at = CarbonImmutable::now('UTC');
            $projected = $this->clock->project($visit, $at);
            $newRevision = $expectedRevision + 1;
            $id = null;

            switch ($action) {
                case 'request':
                    if ($this->status($visit) !== CleaningBookingSessionStatus::InProgress->value
                        || $projected['state'] !== 'running' || $projected['pausePending']) {
                        $this->fail('CLOCK_NOT_RUNNING_OR_PAUSE_PENDING');
                    }
                    $id = (int) DB::table('cleaning_work_pause_requests')->insertGetId([
                        'cleaning_booking_session_id' => $visit->id,
                        'pending_session_id' => $visit->id,
                        'command_key' => $commandKey,
                        'requested_by' => $actor->id,
                        'expected_clock_revision' => $expectedRevision,
                        'status' => 'pending',
                        'requested_at' => $at,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                    break;

                case 'withdraw':
                    $request = $this->lockPendingRequest($visit, (int) $pauseRequestId);
                    if ((int) $request->requested_by !== (int) $actor->id
                        || $this->status($visit) !== CleaningBookingSessionStatus::InProgress->value
                        || $projected['state'] !== 'running') {
                        $this->fail('PAUSE_REQUEST_NOT_WITHDRAWABLE');
                    }
                    $this->finishRequest($visit, $request->id, $actor, $commandKey, 'withdrawn', $at);
                    $id = (int) $request->id;
                    break;

                case 'approve':
                case 'reject':
                    $request = $this->lockPendingRequest($visit, (int) $pauseRequestId);
                    if ($this->status($visit) !== CleaningBookingSessionStatus::InProgress->value
                        || $projected['state'] !== 'running') {
                        $this->fail('CLOCK_NOT_RUNNING');
                    }
                    if ($action === 'approve') {
                        $interval = DB::table('cleaning_work_intervals')
                            ->where('active_session_id', $visit->id)->lockForUpdate()->first();
                        if ($interval === null
                            || CarbonImmutable::parse($interval->started_at, 'UTC') >= $at) {
                            $this->fail('CLOCK_INTERVAL_NOT_READY');
                        }
                        DB::table('cleaning_work_intervals')->where('id', $interval->id)->update([
                            'active_session_id' => null,
                            'end_command_key' => $commandKey,
                            'closed_clock_revision' => $newRevision,
                            'ended_at' => $at->toDateTimeString(),
                            'updated_at' => $at,
                        ]);
                        $visit->forceFill(['status' => CleaningBookingSessionStatus::Paused])->save();
                    }

                    $this->finishRequest(
                        $visit, $request->id, $actor, $commandKey,
                        $action === 'approve' ? 'approved' : 'rejected', $at,
                    );
                    $id = (int) $request->id;
                    break;

                case 'resume':
                    if ($projected['state'] !== 'paused' || $projected['pausePending']) {
                        $this->fail('CLOCK_NOT_PAUSED');
                    }
                    $this->assertCrewCanResume($booking, $visit, $at, (int) $projected['activeSeconds']);
                    DB::table('cleaning_work_intervals')->insert([
                        'cleaning_booking_session_id' => $visit->id,
                        'active_session_id' => $visit->id,
                        'start_command_key' => $commandKey,
                        'opened_clock_revision' => $newRevision,
                        'started_at' => $at->toDateTimeString(),
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                    $visit->forceFill(['status' => CleaningBookingSessionStatus::InProgress])->save();
                    break;
            }

            $visit->forceFill(['clock_revision' => $newRevision])->save();

            return $this->result($visit->fresh(), false, $id, $at);
        }, 3);
    }

    /** @return array{0:CleaningBooking,1:CleaningBookingSession} */
    private function lockVisit(int $bookingId, int $sessionId): array
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        abort_unless((string) ($booking->contract_version ?? '') === 'v3', 404);
        $visit = $booking->sessions()->whereKey($sessionId)->lockForUpdate()->firstOrFail();

        return [$booking, $visit];
    }

    private function status(CleaningBookingSession $visit): string
    {
        return $visit->status?->value ?? (string) $visit->status;
    }

    private function lockPendingRequest(CleaningBookingSession $visit, int $requestId): object
    {
        $request = DB::table('cleaning_work_pause_requests')
            ->where('cleaning_booking_session_id', $visit->id)
            ->where('id', $requestId)->lockForUpdate()->first();
        if ($request === null || $request->status !== 'pending'
            || (int) $request->pending_session_id !== (int) $visit->id) {
            $this->fail('PAUSE_REQUEST_NOT_PENDING');
        }

        return $request;
    }

    private function finishRequest(
        CleaningBookingSession $visit,
        int $requestId,
        User $actor,
        string $commandKey,
        string $status,
        CarbonImmutable $at,
    ): void {
        DB::table('cleaning_work_pause_requests')
            ->where('id', $requestId)
            ->where('cleaning_booking_session_id', $visit->id)
            ->update([
                'pending_session_id' => null,
                'decision_command_key' => $commandKey,
                'decided_by' => $actor->id,
                'decided_at' => $at,
                'status' => $status,
                'updated_at' => $at,
            ]);
    }

    private function replayId(
        CleaningBookingSession $visit,
        User $actor,
        string $action,
        string $key,
        ?int $requestId,
    ): ?int {
        if ($action === 'request') {
            $previous = DB::table('cleaning_work_pause_requests')
                ->where('cleaning_booking_session_id', $visit->id)
                ->where('command_key', $key)->first();

            if ($previous === null) {
                return null;
            }
            if ((int) $previous->requested_by !== (int) $actor->id) {
                $this->fail('CLOCK_COMMAND_KEY_CONFLICT');
            }

            return (int) $previous->id;
        }

        if ($action === 'resume') {
            $previous = DB::table('cleaning_work_intervals')
                ->where('cleaning_booking_session_id', $visit->id)
                ->where('start_command_key', $key)->first();

            return $previous === null ? null : (int) $visit->id;
        }

        $previous = DB::table('cleaning_work_pause_requests')
            ->where('cleaning_booking_session_id', $visit->id)
            ->where('decision_command_key', $key)->first();
        if ($previous === null) {
            return null;
        }

        $expectedStatus = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'withdraw' => 'withdrawn',
        };
        if ((int) $previous->id !== (int) $requestId
            || (int) $previous->decided_by !== (int) $actor->id
            || $previous->status !== $expectedStatus) {
            $this->fail('CLOCK_COMMAND_KEY_CONFLICT');
        }

        return (int) $previous->id;
    }

    private function keyUsedForAnotherCommand(CleaningBookingSession $visit, string $key): bool
    {
        return DB::table('cleaning_work_intervals')
            ->where('cleaning_booking_session_id', $visit->id)
            ->where(static fn ($query) => $query->where('start_command_key', $key)
                ->orWhere('end_command_key', $key))->exists()
            || DB::table('cleaning_work_pause_requests')
                ->where('cleaning_booking_session_id', $visit->id)
                ->where(static fn ($query) => $query->where('command_key', $key)
                    ->orWhere('decision_command_key', $key))->exists();
    }

    /**
     * Resume never silently extends an eight-hour visit or steals a worker
     * assigned to another appointment. Uncertain capacity fails closed.
     */
    private function assertCrewCanResume(
        CleaningBooking $booking,
        CleaningBookingSession $visit,
        CarbonImmutable $at,
        int $activeSeconds,
    ): void {
        $assignments = $visit->workerAssignments()
            ->whereNull('released_at')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->orderBy('worker_id')->lockForUpdate()->get();

        if ($assignments->count() < $visit->requiredWorkerCount()) {
            $this->fail('V3_CREW_INCOMPLETE');
        }

        $maximumMinutes = min(480, (int) ceil((float) $visit->duration_hours * 60));
        $remainingMinutes = $maximumMinutes - intdiv($activeSeconds + 59, 60);
        if ($remainingMinutes < 1 || $visit->work_started_at === null) {
            $this->fail('V3_VISIT_CAPACITY_EXHAUSTED');
        }

        $firstStart = DB::table('cleaning_work_intervals')
            ->where('cleaning_booking_session_id', $visit->id)->orderBy('started_at')->value('started_at');
        $endsAt = $at->addMinutes($remainingMinutes);
        if ($firstStart === null || CarbonImmutable::parse($firstStart, 'UTC')
            ->diffInMinutes($endsAt, false) > 480) {
            $this->fail('V3_VISIT_RESCHEDULE_REQUIRED');
        }

        foreach ($assignments as $assignment) {
            $worker = Worker::query()->whereKey($assignment->worker_id)
                ->lockForUpdate()->first();
            if (! $worker instanceof Worker
                || $this->scheduleConflicts->hasConflictForV3ResumeWindow($worker, $visit, $at, $endsAt)) {
                $this->fail('V3_VISIT_RESCHEDULE_REQUIRED');
            }
        }
    }

    /** @return array{clock:array<string,mixed>,replayed:bool,pauseRequestId:?int} */
    private function result(
        CleaningBookingSession $visit,
        bool $replayed,
        ?int $pauseRequestId,
        ?CarbonImmutable $now = null,
    ): array {
        return [
            'clock' => $this->clock->project($visit, $now),
            'replayed' => $replayed,
            'pauseRequestId' => $pauseRequestId,
        ];
    }

    private function fail(string $reasonCode, int $httpStatus = 409): never
    {
        throw new CleaningV3ClockTransitionException($reasonCode, $httpStatus);
    }
}

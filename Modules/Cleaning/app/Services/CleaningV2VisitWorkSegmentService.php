<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningBookingSessionTaskWorker;

/**
 * Per-visit, per-task evidence of work performed by the actual worker.
 * The supervisor cannot start worker segments on a worker's behalf.
 * None of these operations transfers money or bypasses an unsigned quote.
 */
final class CleaningV2VisitWorkSegmentService
{
    public function __construct(
        private readonly CleaningV2VisitExecutionProvenanceService $provenance,
        private readonly CleaningV2VisitTaskAllocationService $allocations,
        private readonly CleaningV2VisitFundingPreflightService $funding,
    ) {}

    /** @return array<string,mixed> */
    public function supervisorStart(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);

        return DB::transaction(function () use ($actor, $booking, $session): array {
            [$booking, $session] = $this->lockVisit($booking, $session);
            $supervisor = $this->owner($session, $actor);
            $this->assertProven($booking, $session, $supervisor);
            $this->assertFunded($actor, $booking, $session);
            if ($session->replacementRequests()->where('status', 'pending')->exists()) {
                $this->deny('replacement_request_pending');
            }
            if ($session->work_finished_at !== null) {
                $this->deny('visit_already_finished');
            }

            $segments = $session->supervisorWorkSegments()->lockForUpdate()->get();
            if ($segments->isNotEmpty()) {
                if ($segments->count() !== 1 || $segments->first()->ended_at !== null
                    || (int) $segments->first()->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id) {
                    $this->deny('supervisor_work_segment_conflict');
                }

                return $this->snapshot($session);
            }
            if ($session->work_started_at !== null) {
                $this->deny('unproven_existing_work_start');
            }
            $firstTask = $session->executionTasks()->orderBy('id')->first();
            if ($firstTask === null) {
                $this->deny('task_membership_unproven');
            }
            $now = now();
            $session->supervisorWorkSegments()->create([
                'cleaning_booking_session_supervisor_assignment_id' => $supervisor->id,
                'initiating_task_id' => $firstTask->id,
                'started_at' => $now,
                'active_slot' => 1,
            ]);
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::InProgress,
                'work_started_at' => $now,
            ])->save();

            return $this->snapshot($session);
        });
    }

    /** @return array<string,mixed> */
    public function workerStart(User $actor, CleaningBooking $booking, CleaningBookingSession $session, string $taskKey): array
    {
        return $this->workerTransition($actor, $booking, $session, $taskKey, true);
    }

    /** @return array<string,mixed> */
    public function workerFinish(User $actor, CleaningBooking $booking, CleaningBookingSession $session, string $taskKey): array
    {
        return $this->workerTransition($actor, $booking, $session, $taskKey, false);
    }

    /** @return array<string,mixed> */
    public function supervisorFinish(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);

        return DB::transaction(function () use ($actor, $booking, $session): array {
            [$booking, $session] = $this->lockVisit($booking, $session);
            $supervisor = $this->owner($session, $actor);
            $this->assertProven($booking, $session, $supervisor);
            $this->assertFunded($actor, $booking, $session);
            if ($session->replacementRequests()->where('status', 'pending')->exists()) {
                $this->deny('replacement_request_pending');
            }
            $segments = $session->supervisorWorkSegments()->lockForUpdate()->get();
            if ($segments->count() !== 1 || (int) $segments->first()->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id) {
                $this->deny('supervisor_work_segment_missing');
            }
            $segment = $segments->first();
            if ($segment->ended_at !== null) {
                if ($session->status === CleaningBookingSessionStatus::AwaitingCustomerCompletion) {
                    return $this->snapshot($session);
                }
                $this->deny('finished_segment_status_mismatch');
            }
            if ($session->status !== CleaningBookingSessionStatus::InProgress) {
                $this->deny('visit_not_in_progress');
            }
            $members = $session->executionTasks()->with(['workers' => fn ($query) => $query
                ->where('active_slot', 1)->whereNull('released_at')->with('segments')])->get()
                ->flatMap(fn ($task) => $task->workers);
            if ($members->isEmpty() || $members->contains(fn ($member) => $member->segments->count() !== 1 || $member->segments->first()->ended_at === null)) {
                $this->deny('worker_tasks_not_completed');
            }
            $segment->forceFill([
                'ended_at' => now(), 'end_reason' => 'all_tasks_finished',
                'ended_by_user_id' => $actor->id, 'active_slot' => null,
            ])->save();
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
                'work_finished_at' => now(),
            ])->save();

            return $this->snapshot($session);
        });
    }

    /** @return array<string,mixed> */
    public function status(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        if ((int) $session->cleaning_booking_id !== (int) $booking->id || ! $booking->isV2OperationalGroup()) {
            abort(404);
        }

        return $this->snapshot($session);
    }

    /** @return array<string,mixed> */
    private function workerTransition(User $actor, CleaningBooking $booking, CleaningBookingSession $session, string $taskKey, bool $starting): array
    {
        $worker = Worker::query()->where('user_id', $actor->id)->first();
        if ($worker === null) {
            abort(403, 'Worker account required.');
        }

        return DB::transaction(function () use ($actor, $booking, $session, $taskKey, $starting, $worker): array {
            [$booking, $session] = $this->lockVisit($booking, $session);
            $supervisor = $session->activeSupervisorAssignment()->first();
            $this->assertProven($booking, $session, $supervisor);
            $this->assertFunded($supervisor->supervisor, $booking, $session);
            if ($session->status !== CleaningBookingSessionStatus::InProgress || $session->work_started_at === null
                || $session->work_finished_at !== null || $session->supervisorWorkSegments()
                    ->where('active_slot', 1)->whereNull('ended_at')->count() !== 1) {
                $this->deny('supervisor_has_not_started_visit');
            }

            $task = $session->executionTasks()->where('task_key', $taskKey)->first();
            $assignment = $session->workerAssignments()->where('worker_id', $worker->id)
                ->whereNull('released_at')->whereNotNull('accepted_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->first();
            if ($task === null || $assignment === null) {
                // Do not disclose the assignment list to another worker.
                abort(404, 'Task not assigned.');
            }
            $members = $task->workers()->where('active_slot', 1)->whereNull('released_at')
                ->where('cleaning_booking_session_worker_assignment_id', $assignment->id)->lockForUpdate()->get();
            if ($members->count() !== 1) {
                abort(404, 'Task not assigned.');
            }
            /** @var CleaningBookingSessionTaskWorker $member */
            $member = $members->first();
            $segments = $member->segments()->lockForUpdate()->get();
            if ($segments->count() > 1) {
                $this->deny('worker_segment_history_requires_review');
            }
            $segment = $segments->first();

            if ($starting) {
                if ($segment !== null) {
                    if ($segment->ended_at !== null || (int) $segment->recorded_by_user_id !== (int) $actor->id) {
                        $this->deny('worker_task_already_finished');
                    }

                    return $this->snapshot($session);
                }
                $overlap = DB::table('cleaning_booking_session_task_worker_segments as segment')
                    ->join('cleaning_booking_session_task_workers as member', 'member.id', '=', 'segment.cleaning_booking_session_task_worker_id')
                    ->join('cleaning_booking_session_tasks as task', 'task.id', '=', 'member.cleaning_booking_session_task_id')
                    ->where('task.cleaning_booking_session_id', $session->id)
                    ->where('member.cleaning_booking_session_worker_assignment_id', $assignment->id)
                    ->where('segment.active_slot', 1)->whereNull('segment.ended_at')->exists();
                if ($overlap) {
                    $this->deny('worker_task_overlap');
                }
                $member->segments()->create([
                    'started_at' => now(), 'recorded_by_user_id' => $actor->id, 'active_slot' => 1,
                ]);
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                    'work_started_at' => $assignment->work_started_at ?? now(),
                ])->save();
            } else {
                if ($segment === null) {
                    $this->deny('worker_task_not_started');
                }
                if ($segment->ended_at === null) {
                    $segment->forceFill([
                        'ended_at' => now(), 'end_reason' => 'finished', 'active_slot' => null,
                    ])->save();
                }
                $remaining = DB::table('cleaning_booking_session_task_workers as member')
                    ->join('cleaning_booking_session_tasks as task', 'task.id', '=', 'member.cleaning_booking_session_task_id')
                    ->where('task.cleaning_booking_session_id', $session->id)
                    ->where('member.cleaning_booking_session_worker_assignment_id', $assignment->id)
                    ->where('member.active_slot', 1)
                    ->whereNotIn('member.id', DB::table('cleaning_booking_session_task_worker_segments')
                        ->select('cleaning_booking_session_task_worker_id')->whereNotNull('ended_at'))
                    ->exists();
                if (! $remaining) {
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                        'work_finished_at' => now(),
                    ])->save();
                }
            }

            return $this->snapshot($session);
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(CleaningBookingSession $session): array
    {
        $tasks = $session->executionTasks()->with(['workers.workerAssignment', 'workers.segments'])->orderBy('id')->get();

        return [
            'visitId' => (int) $session->id,
            'status' => (string) ($session->status?->value ?? $session->status),
            'workStartedAt' => $session->work_started_at?->toIso8601String(),
            'workFinishedAt' => $session->work_finished_at?->toIso8601String(),
            'supervisorSegments' => $session->supervisorWorkSegments()->orderBy('id')->get()
                ->map(static fn ($segment): array => [
                    'startedAt' => $segment->started_at?->toIso8601String(),
                    'finishedAt' => $segment->ended_at?->toIso8601String(),
                ])->all(),
            'tasks' => $tasks->map(static fn ($task): array => [
                'taskKey' => $task->task_key,
                'members' => $task->workers->where('active_slot', 1)->map(static fn ($member): array => [
                    'workerId' => (int) $member->workerAssignment?->worker_id,
                    'segments' => $member->segments->map(static fn ($segment): array => [
                        'startedAt' => $segment->started_at?->toIso8601String(),
                        'finishedAt' => $segment->ended_at?->toIso8601String(),
                    ])->all(),
                ])->values()->all(),
            ])->all(),
            'paymentStatus' => (string) $session->payment_status,
            'settlementEnabled' => false,
        ];
    }

    /** @return array{CleaningBooking,CleaningBookingSession} */
    private function lockVisit(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $session = CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)
            ->whereKey($session->id)->lockForUpdate()->firstOrFail();

        return [$booking, $session];
    }

    private function owner(CleaningBookingSession $session, User $actor): CleaningBookingSessionSupervisorAssignment
    {
        $supervisors = $session->supervisorAssignments()->where('active_slot', 1)->whereNull('released_at')->get();
        if ($supervisors->count() !== 1 || (int) $supervisors->first()->supervisor_user_id !== (int) $actor->id) {
            abort(404, 'Visit not found.');
        }

        return $supervisors->first();
    }

    private function assertProven(CleaningBooking $booking, CleaningBookingSession $session, ?CleaningBookingSessionSupervisorAssignment $supervisor): void
    {
        if (! $booking->isV2OperationalGroup() || ! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $session->isTerminal() || $supervisor === null
            || (float) $supervisor->compensation_hourly_rate <= 0
            || ! $booking->is_pricing_final || ! $session->is_pricing_final) {
            $this->deny('approved_visit_contract_unproven');
        }
        if (! $session->supervisorAttendances()
            ->where('cleaning_booking_session_supervisor_assignment_id', $supervisor->id)
            ->whereNotNull('started_travel_at')->whereNotNull('arrived_at')->exists()) {
            $this->deny('supervisor_arrival_unverified');
        }
        $source = $this->provenance->evaluate($booking, $session);
        if (! $source['valid'] || $source['taskCandidates'] === []
            || ! $this->allocations->membershipProven($session, $source['taskCandidates'])) {
            $this->deny('signed_task_membership_unproven');
        }
        if (! app(CleaningV2VisitWorkerAttestationService::class)->allPresent($session, $supervisor->id)) {
            $this->deny('certified_attendance_missing');
        }
        if ($session->executionVerifications()->where('active_slot', 1)->where('status', 'verified')
            ->whereNotNull('verified_at')->whereNotNull('consumed_at')->whereNull('invalidated_at')
            ->where('customer_user_id', $booking->customer_id)
            ->where('cleaning_booking_session_supervisor_assignment_id', $supervisor->id)->count() !== 1) {
            $this->deny('customer_verification_missing');
        }
    }

    private function assertFunded(User $supervisor, CleaningBooking $booking, CleaningBookingSession $session): void
    {
        $financial = $this->funding->review($supervisor, $booking, $session);
        if ($financial['fundingProven'] !== true || $financial['startWorkAllowed'] !== true) {
            $this->deny('approved_funding_source_unproven');
        }
    }

    private function deny(string $reason): never
    {
        throw ValidationException::withMessages(['execution' => [$reason]]);
    }
}

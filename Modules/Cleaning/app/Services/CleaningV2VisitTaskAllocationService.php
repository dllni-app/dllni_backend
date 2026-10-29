<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionTask;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;

/**
 * G2B: auditable, visit-scoped task membership, without work-start or payout.
 * The supervisor must explicitly assign every signed task to accepted workers.
 * Reallocation after the initial allocation needs its own guarded incident flow.
 */
final class CleaningV2VisitTaskAllocationService
{
    public function __construct(private readonly CleaningV2VisitExecutionProvenanceService $provenance) {}

    /**
     * @param list<array{taskKey:string,workerIds:list<int>}> $requested
     * @return list<array{taskKey:string,sourceKind:string,requiredWorkers:int,workerIds:list<int>}>
     */
    public function allocate(User $actor, CleaningBooking $booking, CleaningBookingSession $session, array $requested): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);

        return DB::transaction(function () use ($actor, $booking, $session, $requested): array {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)->whereKey($session->id)
                ->lockForUpdate()->firstOrFail();

            if (! $booking->isV2OperationalGroup() || ! $session->isSupervisionInitialized()
                || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
                || $session->isTerminal()
                || in_array($booking->status, [CleaningBookingStatus::Cancelled, CleaningBookingStatus::Completed], true)) {
                $this->deny('visit_not_allocatable');
            }

            $supervisors = $session->supervisorAssignments()
                ->where('active_slot', 1)->whereNull('released_at')->lockForUpdate()->get();
            if ($supervisors->count() !== 1 || (int) $supervisors->first()->supervisor_user_id !== (int) $actor->id) {
                // Do not reveal the identity or existence of another supervisor's visit.
                abort(404, 'Visit not found.');
            }
            /** @var CleaningBookingSessionSupervisorAssignment $supervisor */
            $supervisor = $supervisors->first();
            if ((float) $supervisor->compensation_hourly_rate <= 0) {
                $this->deny('supervisor_compensation_unconfigured');
            }

            $source = $this->provenance->evaluate($booking, $session);
            if (! $source['valid'] || $source['taskCandidates'] === []) {
                $this->deny('task_source_unproven');
            }

            $assignments = $session->workerAssignments()
                ->whereNull('released_at')->whereNotNull('accepted_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->lockForUpdate()->get()->keyBy('worker_id');
            if ($assignments->count() !== $session->requiredWorkerCount()) {
                $this->deny('team_coverage_incomplete');
            }

            $candidates = collect($source['taskCandidates'])->keyBy('taskKey');
            if (count($requested) !== $candidates->count()) {
                $this->deny('task_set_incomplete');
            }
            $normalized = [];
            foreach ($requested as $entry) {
                $key = mb_trim((string) ($entry['taskKey'] ?? ''));
                $candidate = $candidates->get($key);
                if (! is_array($candidate) || array_key_exists($key, $normalized)) {
                    $this->deny('unknown_or_duplicate_task');
                }
                $ids = $entry['workerIds'] ?? [];
                if (! is_array($ids) || count($ids) !== (int) $candidate['requiredWorkers']) {
                    $this->deny('task_worker_count_mismatch');
                }
                $ids = array_map('intval', $ids);
                if (count(array_unique($ids)) !== count($ids) || collect($ids)->contains(fn (int $id): bool => $id <= 0 || ! $assignments->has($id))) {
                    $this->deny('worker_not_accepted_for_visit');
                }
                sort($ids, SORT_NUMERIC);
                $normalized[$key] = [...$candidate, 'workerIds' => $ids];
            }
            ksort($normalized);

            // A worker cannot be allocated to overlapping signed schedules OR
            // room periods. Non-overlapping tasks may reuse the same worker.
            $intervals = [];
            foreach ($normalized as $task) {
                $start = null;
                $end = null;
                if ($task['sourceKind'] === 'room_schedule') {
                    $key = mb_substr($task['taskKey'], mb_strlen('schedule:'));
                    $sourceSchedule = $booking->roomSchedules()
                        ->where('cleaning_booking_session_id', $session->id)->where('schedule_key', $key)->first();
                    $start = $sourceSchedule?->starts_at;
                    $end = $sourceSchedule?->ends_at;
                } elseif ($task['sourceKind'] === 'room_period') {
                    $period = $session->roomPeriods()->where('client_key', mb_substr($task['taskKey'], 12))->first();
                    if ($period !== null && $period->scheduled_date !== null
                        && $period->scheduled_time !== null && (int) $period->estimated_duration_minutes > 0) {
                        $start = CarbonImmutable::parse(
                            $period->scheduled_date->toDateString().' '.$period->scheduled_time,
                            config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
                        );
                        $end = $start->addMinutes((int) $period->estimated_duration_minutes);
                    }
                } else {
                    continue;
                }
                if ($start === null || $end === null || $end->lessThanOrEqualTo($start)) {
                    $this->deny('task_source_unproven');
                }
                foreach ($task['workerIds'] as $workerId) {
                    foreach ($intervals[$workerId] ?? [] as $period) {
                        if ($start->lessThan($period[1]) && $period[0]->lessThan($end)) {
                            $this->deny('worker_task_schedule_overlap');
                        }
                    }
                    $intervals[$workerId][] = [$start, $end];
                }
            }

            $existing = $session->executionTasks()->with('workers.workerAssignment')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                // Immutable and idempotent only for an *identical* initial assignment.
                if (! $this->matches($existing->all(), $normalized, $session)) {
                    $this->deny('task_allocation_already_exists');
                }

                return array_values($normalized);
            }

            foreach ($normalized as $task) {
                $sourceId = null;
                if ($task['sourceKind'] === 'room_schedule') {
                    $sourceId = $booking->roomSchedules()->where('cleaning_booking_session_id', $session->id)
                        ->where('schedule_key', mb_substr($task['taskKey'], 9))->value('id');
                } elseif ($task['sourceKind'] === 'room_period') {
                    $sourceId = $session->roomPeriods()->where('client_key', mb_substr($task['taskKey'], 12))->value('id');
                }
                if ($task['sourceKind'] !== 'visit_scope' && ! $sourceId) {
                    $this->deny('task_source_unproven');
                }

                $saved = $session->executionTasks()->create([
                    'task_key' => $task['taskKey'],
                    'source_kind' => $task['sourceKind'],
                    'cleaning_room_schedule_id' => $task['sourceKind'] === 'room_schedule' ? $sourceId : null,
                    'cleaning_booking_room_period_id' => $task['sourceKind'] === 'room_period' ? $sourceId : null,
                    'required_workers' => $task['requiredWorkers'],
                    'provenance_snapshot' => [
                        'signedSessionId' => $session->id,
                        'approvedTaskKey' => $task['taskKey'],
                        'signedSourceKind' => $task['sourceKind'],
                        'approvedWorkers' => $task['requiredWorkers'],
                    ],
                    'status' => 'allocated',
                ]);
                foreach ($task['workerIds'] as $workerId) {
                    $saved->workers()->create([
                        'cleaning_booking_session_worker_assignment_id' => $assignments->get($workerId)->id,
                        'allocated_by_supervisor_assignment_id' => $supervisor->id,
                        'allocated_by_user_id' => $actor->id,
                        'membership_source' => 'supervisor_explicit',
                        'allocated_at' => now(),
                        'active_slot' => 1,
                    ]);
                }
            }

            return array_values($normalized);
        });
    }

    /** @param list<array{taskKey:string,sourceKind:string,requiredWorkers:int}> $candidates */
    public function membershipProven(CleaningBookingSession $session, array $candidates): bool
    {
        if ($candidates === []) {
            return false;
        }
        $expected = collect($candidates)->keyBy('taskKey');
        $tasks = $session->executionTasks()->with('workers.workerAssignment')->get();
        if ($tasks->count() !== $expected->count()) {
            return false;
        }
        foreach ($tasks as $task) {
            $source = $expected->get($task->task_key);
            if (! is_array($source) || $task->source_kind !== $source['sourceKind']
                || (int) $task->required_workers !== (int) $source['requiredWorkers']
                || $task->status !== 'allocated') {
                return false;
            }
            $members = $task->workers->filter(fn ($member): bool => $member->active_slot === 1 && $member->released_at === null);
            if ($members->count() !== (int) $source['requiredWorkers']) {
                return false;
            }
            $ids = [];
            foreach ($members as $member) {
                $worker = $member->workerAssignment;
                if ($member->membership_source !== 'supervisor_explicit'
                    || $member->allocated_by_supervisor_assignment_id === null
                    || $member->allocated_by_user_id === null
                    || $worker === null
                    || (int) $worker->cleaning_booking_session_id !== (int) $session->id
                    || $worker->released_at !== null || $worker->accepted_at === null
                    || ! in_array((string) ($worker->status?->value ?? $worker->status), CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                    return false;
                }
                $activeSupervisor = $session->activeSupervisorAssignment()->first();
                if ($activeSupervisor === null || (int) $member->allocated_by_supervisor_assignment_id !== (int) $activeSupervisor->id) {
                    return false;
                }
                $ids[] = $worker->worker_id;
            }
            if (count(array_unique($ids)) !== count($ids)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<CleaningBookingSessionTask> $existing
     *  @param array<string,array{taskKey:string,sourceKind:string,requiredWorkers:int,workerIds:list<int>}> $expected */
    private function matches(array $existing, array $expected, CleaningBookingSession $session): bool
    {
        if (count($existing) !== count($expected)) {
            return false;
        }
        foreach ($existing as $task) {
            if (! isset($expected[$task->task_key]) || $task->status !== 'allocated'
                || $task->source_kind !== $expected[$task->task_key]['sourceKind']
                || (int) $task->required_workers !== $expected[$task->task_key]['requiredWorkers']) {
                return false;
            }
            $workers = [];
            foreach ($task->workers as $member) {
                if ($member->released_at !== null || (int) $member->active_slot !== 1
                    || $member->membership_source !== 'supervisor_explicit'
                    || (int) $member->allocated_by_supervisor_assignment_id !== (int) $session->activeSupervisorAssignment?->id) {
                    return false;
                }
                $workers[] = (int) $member->workerAssignment?->worker_id;
            }
            sort($workers, SORT_NUMERIC);
            if ($workers !== $expected[$task->task_key]['workerIds']) {
                return false;
            }
        }

        return true;
    }

    private function deny(string $code): never
    {
        throw ValidationException::withMessages(['taskAssignments' => [$code]]);
    }
}

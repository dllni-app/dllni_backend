<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningV2VisitExecutionCapabilityService
{
    public function __construct(
        private readonly CleaningV2VisitExecutionProvenanceService $provenance,
        private readonly SupervisorScheduledV2Service $scheduledV2,
        private readonly CleaningV2VisitTaskAllocationService $allocations,
    ) {}

    /**
     * @return array{executionCapability:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>,allowedActions:list<string>}
     */
    public function readiness(CleaningBooking $booking, CleaningBookingSession $session, bool $verifiedFunding = false): array
    {
        $reasons = [];
        $taskCandidates = [];

        if ((int) $session->cleaning_booking_id !== (int) $booking->id
            || (string) $booking->booking_kind === 'supervised_team'
            || ! in_array((string) $booking->booking_kind, ['standard', 'open_time'], true)
            || (int) $booking->number_of_workers <= 1) {
            $reasons[] = 'unsupported_contract';
        }

        if (! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR) {
            $reasons[] = 'uninitialized';
        }

        if (in_array($booking->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true)
            || $session->isTerminal()) {
            $reasons[] = 'terminal';
        }

        $activeAssignments = $session->supervisorAssignments()
            ->where('active_slot', 1)
            ->whereNull('released_at')
            ->get();
        if ($activeAssignments->count() !== 1) {
            $reasons[] = $activeAssignments->isEmpty() ? 'supervisor_unassigned' : 'supervisor_ambiguous';
        } elseif ((float) $activeAssignments->first()->compensation_hourly_rate <= 0) {
            $reasons[] = 'compensation_unconfigured';
        } elseif (! $session->supervisorAttendances()
            ->where('cleaning_booking_session_supervisor_assignment_id', $activeAssignments->first()->id)
            ->whereNotNull('arrived_at')->exists()) {
            $reasons[] = 'supervisor_arrival_unverified';
        }

        $provenance = $this->provenance->evaluate($booking, $session);
        $taskCandidates = $provenance['taskCandidates'];
        array_push($reasons, ...$provenance['reasonCodes']);

        if ((string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING
            && (string) $booking->booking_kind === 'standard'
            && $taskCandidates !== []
            && ! $this->scheduledV2->isEligible($booking)) {
            $reasons[] = 'unsupported_contract';
        }

        if ($taskCandidates !== [] && ! $this->allocations->membershipProven($session, $taskCandidates)) {
            $reasons[] = 'task_worker_membership_unproven';
        }

        if ($taskCandidates === [] && $provenance['reasonCodes'] === []) {
            $reasons[] = 'task_source_missing';
        }

        // A confirmed customer-only challenge is necessary but never enough
        // to start work. It must be consumed, bound to the active visit owner,
        // and cannot be inferred from the booking's legacy verification code.
        $activeSupervisorId = $activeAssignments->count() === 1 ? $activeAssignments->first()->id : null;
        $verified = $session->executionVerifications()->where('active_slot', 1)
            ->where('status', 'verified')->whereNotNull('verified_at')
            ->whereNotNull('consumed_at')->whereNull('invalidated_at')
            ->where('customer_user_id', $booking->customer_id)
            ->when($activeSupervisorId !== null, fn ($query) => $query
                ->where('cleaning_booking_session_supervisor_assignment_id', $activeSupervisorId))
            ->count();
        if ($activeSupervisorId === null || $verified !== 1) {
            $reasons[] = 'customer_supervisor_verification_unproven';
        }
        if ($activeSupervisorId === null || ! app(CleaningV2VisitWorkerAttestationService::class)->allPresent($session, $activeSupervisorId)) {
            $reasons[] = 'actual_attendance_not_certified';
        }

        // The funding decision must originate from the authoritative funding
        // verifier, never from a client flag. Callers without verified evidence
        // are deliberately blocked by default.
        if (! $verifiedFunding) {
            $reasons[] = 'execution_path_not_enabled';
        }

        $capable = $reasons === [];
        $allowed = [];
        if ($capable && $session->work_started_at === null) {
            $allowed = ['start'];
        } elseif ($capable && $session->status === CleaningBookingSessionStatus::InProgress
            && $session->supervisorWorkSegments()->where('active_slot', 1)->whereNull('ended_at')->count() === 1) {
            $members = $session->executionTasks()->with('workers.segments')->get()
                ->flatMap(fn ($task) => $task->workers
                    ->filter(fn ($member) => (int) $member->active_slot === 1 && $member->released_at === null));
            if ($members->isNotEmpty() && $members->every(fn ($member) => $member->segments->count() === 1 && $member->segments->first()->ended_at !== null)) {
                $allowed = ['finish'];
            }
        }

        return [
            'executionCapability' => $capable,
            'reasonCodes' => array_values(array_unique($reasons)),
            'taskCandidates' => $taskCandidates,
            'allowedActions' => $allowed,
        ];
    }
}

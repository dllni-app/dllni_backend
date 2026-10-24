<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningV2VisitExecutionCapabilityService
{
    public function __construct(
        private readonly CleaningV2VisitExecutionProvenanceService $provenance,
        private readonly SupervisorScheduledV2Service $scheduledV2,
    ) {}

    /**
     * @return array{executionCapability:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>,allowedActions:list<string>}
     */
    public function readiness(CleaningBooking $booking, CleaningBookingSession $session): array
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

        if ($taskCandidates !== []) {
            $reasons[] = 'task_worker_membership_unproven';
        }

        if ($taskCandidates === [] && $provenance['reasonCodes'] === []) {
            $reasons[] = 'task_source_missing';
        }

        return [
            'executionCapability' => false,
            'reasonCodes' => array_values(array_unique($reasons)),
            'taskCandidates' => $taskCandidates,
            'allowedActions' => [],
        ];
    }
}

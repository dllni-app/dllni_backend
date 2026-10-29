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
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAttestation;

/**
 * G2C-2: explicit visit-worker physical presence attested by the active supervisor.
 * No allocation changes, automatic no-show release, work start or payment mutation.
 */
final class CleaningV2VisitWorkerAttestationService
{
    public function __construct(
        private readonly CleaningV2VisitExecutionProvenanceService $provenance,
        private readonly CleaningV2VisitTaskAllocationService $allocations,
    ) {}

    /** @return array<string,mixed> */
    public function certify(User $actor, CleaningBooking $booking, CleaningBookingSession $session, int $workerId, string $outcome, ?string $note): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        if (! in_array($outcome, ['present', 'no_show'], true)) {
            $this->deny('unsupported_attendance_outcome');
        }

        return DB::transaction(function () use ($actor, $booking, $session, $workerId, $outcome, $note): array {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)
                ->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $supervisor = $this->owner($session, $actor);
            $this->assertVisit($booking, $session, $supervisor);

            $workers = $session->workerAssignments()
                ->whereNull('released_at')->whereNotNull('accepted_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->lockForUpdate()->get();
            if ($workers->count() !== $session->requiredWorkerCount()
                || $workers->pluck('worker_id')->unique()->count() !== $workers->count()) {
                $this->deny('visit_coverage_incomplete');
            }
            $assignment = $workers->firstWhere('worker_id', $workerId);
            if ($assignment === null) {
                $this->deny('worker_not_accepted_for_visit');
            }
            $existing = $session->workerAttestations()->where('cleaning_booking_session_worker_assignment_id', $assignment->id)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->outcome !== $outcome || (int) $existing->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id) {
                    $this->deny('attendance_already_certified_conflict');
                }

                // Same decision is immutable and idempotent: don't change original note/actor/time.
                return $this->snapshot($session, $supervisor);
            }

            if ($session->executionVerifications()->where('active_slot', 1)
                ->where('status', 'verified')->whereNotNull('consumed_at')->exists()) {
                $this->deny('verification_already_consumed');
            }
            if ($outcome === 'present') {
                if ($assignment->started_travel_at === null || $assignment->arrived_at === null) {
                    $this->deny('worker_arrival_unverified');
                }
            } else {
                // A no-show is a supervisor-reported incident, not an automatic worker rejection.
                if (mb_strlen(mb_trim((string) $note)) < 10) {
                    $this->deny('no_show_reason_required');
                }
                $when = $session->scheduled_date?->toDateString();
                $time = mb_trim((string) $session->scheduled_time);
                if ($when === null || $time === '' || CarbonImmutable::now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))
                    ->lessThan(CarbonImmutable::parse($when.' '.$time, config('cleaning_booking_v2.timezone', 'Asia/Damascus')))) {
                    $this->deny('no_show_before_visit_start');
                }
            }

            $session->workerAttestations()->create([
                'cleaning_booking_session_worker_assignment_id' => $assignment->id,
                'cleaning_booking_session_supervisor_assignment_id' => $supervisor->id,
                'recorded_by_user_id' => $actor->id,
                'outcome' => $outcome,
                'note' => $outcome === 'no_show' ? mb_trim((string) $note) : null,
                'certified_at' => now(),
            ]);

            return $this->snapshot($session, $supervisor);
        });
    }

    /** @return array<string,mixed> */
    public function status(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        $supervisor = $this->owner($session, $actor);
        $allowed = true;
        try {
            $this->assertVisit($booking, $session, $supervisor);
        } catch (ValidationException) {
            $allowed = false;
        }

        $verified = $session->executionVerifications()->where('active_slot', 1)
            ->where('status', 'verified')->whereNotNull('consumed_at')->exists();

        $accepted = $session->workerAssignments()->whereNull('released_at')
            ->whereNotNull('accepted_at')->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->get();
        $coverage = $accepted->count() === $session->requiredWorkerCount()
            && $accepted->pluck('worker_id')->unique()->count() === $accepted->count();

        return [...$this->snapshot($session, $supervisor), 'certificationAvailable' => $allowed && $coverage && ! $verified];
    }

    public function allPresent(CleaningBookingSession $session, int $supervisorAssignmentId): bool
    {
        $accepted = $session->workerAssignments()
            ->whereNull('released_at')->whereNotNull('accepted_at')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->get();
        if ($accepted->count() !== $session->requiredWorkerCount()
            || $accepted->pluck('worker_id')->unique()->count() !== $accepted->count()
            || $accepted->contains(fn ($worker): bool => $worker->started_travel_at === null || $worker->arrived_at === null)) {
            return false;
        }
        // Historical no-show certificates remain immutable after an approved
        // replacement. Only certificates for the current accepted team count.
        $rows = $session->workerAttestations()
            ->whereIn('cleaning_booking_session_worker_assignment_id', $accepted->pluck('id'))
            ->get();

        return $rows->count() === $accepted->count()
            && $rows->every(fn (CleaningBookingSessionWorkerAttestation $row): bool => $row->outcome === 'present'
                && (int) $row->cleaning_booking_session_supervisor_assignment_id === $supervisorAssignmentId
                && $accepted->contains('id', $row->cleaning_booking_session_worker_assignment_id));
    }

    /** @return array<string,mixed> */
    private function snapshot(CleaningBookingSession $session, CleaningBookingSessionSupervisorAssignment $supervisor): array
    {
        $rows = $session->workerAttestations()->with('workerAssignment')->orderBy('id')->get();
        $byAssignment = $rows->keyBy('cleaning_booking_session_worker_assignment_id');
        $workers = $session->workerAssignments()->whereNull('released_at')
            ->whereNotNull('accepted_at')->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->with('worker.user')->get();
        $date = $session->scheduled_date?->toDateString();
        $time = mb_trim((string) $session->scheduled_time);
        $noShowAllowed = $date !== null && $time !== ''
            && CarbonImmutable::now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))
                ->greaterThanOrEqualTo(CarbonImmutable::parse($date.' '.$time, config('cleaning_booking_v2.timezone', 'Asia/Damascus')));

        return [
            'visitId' => (int) $session->id,
            'requiredWorkers' => $session->requiredWorkerCount(),
            'certifiedWorkers' => $rows->where('outcome', 'present')->count(),
            'noShowWorkers' => $rows->where('outcome', 'no_show')->count(),
            'actualAttendanceCertified' => $this->allPresent($session, $supervisor->id),
            'noShowAllowed' => $noShowAllowed,
            'workers' => $workers->map(static fn ($worker): array => [
                'workerId' => (int) $worker->worker_id,
                'workerName' => $worker->worker?->user?->name,
                'selfReportedArrivalAt' => $worker->arrived_at?->toIso8601String(),
                'certifiedOutcome' => $byAssignment->get($worker->id)?->outcome,
            ])->values()->all(),
            'attestations' => $rows->map(static fn (CleaningBookingSessionWorkerAttestation $row): array => [
                'workerId' => (int) $row->workerAssignment?->worker_id,
                'outcome' => $row->outcome,
                'certifiedAt' => $row->certified_at?->toIso8601String(),
                'needsResolution' => $row->outcome === 'no_show',
            ])->values()->all(),
            'workExecutionEnabled' => false,
            'settlementEnabled' => false,
        ];
    }

    private function owner(CleaningBookingSession $session, User $actor): CleaningBookingSessionSupervisorAssignment
    {
        $active = $session->supervisorAssignments()->where('active_slot', 1)->whereNull('released_at')->get();
        if ($active->count() !== 1 || (int) $active->first()->supervisor_user_id !== (int) $actor->id) {
            abort(404, 'Visit not found.');
        }

        return $active->first();
    }

    private function assertVisit(CleaningBooking $booking, CleaningBookingSession $session, CleaningBookingSessionSupervisorAssignment $supervisor): void
    {
        if ((int) $session->cleaning_booking_id !== (int) $booking->id
            || ! $booking->isV2OperationalGroup() || ! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $session->isTerminal() || $session->work_started_at !== null
            || in_array($booking->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true)) {
            $this->deny('visit_not_certifiable');
        }
        if ((float) $supervisor->compensation_hourly_rate <= 0) {
            $this->deny('supervisor_compensation_unconfigured');
        }
        if (! $session->supervisorAttendances()->where('cleaning_booking_session_supervisor_assignment_id', $supervisor->id)
            ->whereNotNull('started_travel_at')->whereNotNull('arrived_at')->exists()) {
            $this->deny('supervisor_arrival_unverified');
        }
        $source = $this->provenance->evaluate($booking, $session);
        if (! $source['valid'] || $source['taskCandidates'] === []
            || ! $this->allocations->membershipProven($session, $source['taskCandidates'])) {
            $this->deny('task_membership_unproven');
        }
    }

    private function deny(string $code): never
    {
        throw ValidationException::withMessages(['attendance' => [$code]]);
    }
}

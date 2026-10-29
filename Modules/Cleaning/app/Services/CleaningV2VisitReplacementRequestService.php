<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionReplacementRequest;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAttestation;

/**
 * V2 no-show replacement *request*, never automatic reassignment or payout.
 * The underlying worker's original attendance decision remains immutable.
 */
final class CleaningV2VisitReplacementRequestService
{
    public function __construct(
        private readonly CleaningV2VisitExecutionProvenanceService $provenance,
        private readonly CleaningV2VisitTaskAllocationService $allocations,
    ) {}

    /** @return array<string,mixed> */
    public function request(User $actor, CleaningBooking $booking, CleaningBookingSession $session, int $workerId, string $reason): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        $reason = mb_trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            $this->deny('replacement_reason_required');
        }

        return DB::transaction(function () use ($actor, $booking, $session, $workerId, $reason): array {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)
                ->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $supervisor = $this->owner($session, $actor);
            $this->assertRequestable($booking, $session, $supervisor);

            $worker = $session->workerAssignments()->where('worker_id', $workerId)
                ->whereNull('released_at')->whereNotNull('accepted_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->lockForUpdate()->first();
            if ($worker === null) {
                $this->deny('worker_not_active_in_visit');
            }
            /** @var CleaningBookingSessionWorkerAttestation|null $attestation */
            $attestation = $session->workerAttestations()
                ->where('cleaning_booking_session_worker_assignment_id', $worker->id)
                ->lockForUpdate()->first();
            if ($attestation === null || $attestation->outcome !== 'no_show'
                || (int) $attestation->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id) {
                $this->deny('certified_no_show_required');
            }

            $existing = $session->replacementRequests()
                ->where('cleaning_booking_session_worker_attestation_id', $attestation->id)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->reason !== $reason || (int) $existing->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id) {
                    $this->deny('replacement_request_already_exists');
                }

                return $this->summary($session);
            }

            $session->replacementRequests()->create([
                'cleaning_booking_session_worker_assignment_id' => $worker->id,
                'cleaning_booking_session_worker_attestation_id' => $attestation->id,
                'cleaning_booking_session_supervisor_assignment_id' => $supervisor->id,
                'requested_by_user_id' => $actor->id,
                'status' => 'pending',
                'reason' => $reason,
            ]);

            return $this->summary($session);
        });
    }

    /** @return array<string,mixed> */
    public function status(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        $supervisor = $this->owner($session, $actor);
        $available = true;
        try {
            $this->assertRequestable($booking, $session, $supervisor);
        } catch (ValidationException) {
            $available = false;
        }

        return [...$this->summary($session), 'requestAvailable' => $available];
    }

    /** Independent administrative authorization; the reporting supervisor cannot approve their own incident. */
    public function approve(User $approver, CleaningBooking $booking, CleaningBookingSession $session, int $requestId): array
    {
        if (! $approver->hasAnyRole(['admin', 'super_admin'])) {
            abort(403);
        }

        return DB::transaction(function () use ($approver, $booking, $session, $requestId): array {
            [$booking, $session, $request] = $this->lockRequest($booking, $session, $requestId);
            if ((int) $request->requested_by_user_id === (int) $approver->id) {
                $this->deny('independent_approval_required');
            }
            if ($request->status === 'approved' && (int) $request->approved_by_user_id === (int) $approver->id) {
                return $this->summary($session);
            }
            if ($request->status !== 'pending') {
                $this->deny('replacement_not_pending');
            }
            $this->assertRequestable($booking, $session, $session->supervisorAssignments()->findOrFail($request->cleaning_booking_session_supervisor_assignment_id));
            $request->update(['status' => 'approved', 'approved_by_user_id' => $approver->id, 'approved_at' => now()]);

            return $this->summary($session);
        }, 3);
    }

    /** Supervisor nominates the replacement; no assignment exists until that worker explicitly opts in. */
    public function nominate(User $supervisor, CleaningBooking $booking, CleaningBookingSession $session, int $requestId, int $workerId): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($supervisor);

        return DB::transaction(function () use ($supervisor, $booking, $session, $requestId, $workerId): array {
            [$booking, $session, $request] = $this->lockRequest($booking, $session, $requestId);
            $owner = $this->owner($session, $supervisor);
            $this->assertRequestable($booking, $session, $owner);
            if ((int) $request->cleaning_booking_session_supervisor_assignment_id !== (int) $owner->id
                || $request->status !== 'approved' || $request->candidate_worker_id !== null) {
                $this->deny('replacement_not_open_for_nomination');
            }
            if (! Worker::query()->activeAvailable()->whereKey($workerId)->exists()
                || $session->workerAssignments()->where('worker_id', $workerId)->exists()) {
                $this->deny('replacement_worker_unavailable');
            }
            $request->update(['candidate_worker_id' => $workerId, 'status' => 'offered']);

            return $this->summary($session);
        }, 3);
    }

    /** Worker opt-in is tied to the authenticated user and this exact visit. */
    public function accept(User $workerUser, CleaningBooking $booking, CleaningBookingSession $session, int $requestId): array
    {
        return DB::transaction(function () use ($workerUser, $booking, $session, $requestId): array {
            [$booking, $session, $request] = $this->lockRequest($booking, $session, $requestId);
            if ($request->status !== 'offered' || $request->candidate_worker_id === null
                || (int) $workerUser->worker?->id !== (int) $request->candidate_worker_id
                || ! Worker::query()->activeAvailable()->whereKey($request->candidate_worker_id)->exists()) {
                $this->deny('replacement_offer_not_available');
            }
            $owner = $session->supervisorAssignments()->findOrFail($request->cleaning_booking_session_supervisor_assignment_id);
            $this->assertRequestable($booking, $session, $owner);
            $request->update(['status' => 'accepted', 'accepted_by_user_id' => $workerUser->id, 'accepted_at' => now()]);

            return $this->summary($session);
        }, 3);
    }

    /** Reallocate only the no-show worker's original memberships after independent approval and opt-in. */
    public function reallocate(User $supervisor, CleaningBooking $booking, CleaningBookingSession $session, int $requestId): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($supervisor);

        return DB::transaction(function () use ($supervisor, $booking, $session, $requestId): array {
            [$booking, $session, $request] = $this->lockRequest($booking, $session, $requestId);
            $owner = $this->owner($session, $supervisor);
            $this->assertRequestable($booking, $session, $owner);
            if ($request->status !== 'accepted' || $request->approved_at === null || $request->accepted_at === null
                || (int) $request->cleaning_booking_session_supervisor_assignment_id !== (int) $owner->id
                || $request->candidate_worker_id === null) {
                $this->deny('replacement_not_ready');
            }
            $original = $session->workerAssignments()->whereKey($request->cleaning_booking_session_worker_assignment_id)
                ->whereNull('released_at')->lockForUpdate()->firstOrFail();
            // Pricing belongs to the signed customer agreement and its funding
            // ledger. Without a separately approved financial amendment a priced
            // worker slot cannot be transferred to a different person.
            if (collect(['service_share_amount', 'travel_fee', 'admin_margin_amount', 'worker_amount'])
                ->contains(fn (string $field): bool => $original->{$field} !== null && (float) $original->{$field} !== 0.0)) {
                $this->deny('replacement_financial_amendment_required');
            }
            $memberships = DB::table('cleaning_booking_session_task_workers as member')
                ->join('cleaning_booking_session_tasks as task', 'task.id', '=', 'member.cleaning_booking_session_task_id')
                ->where('task.cleaning_booking_session_id', $session->id)
                ->where('member.cleaning_booking_session_worker_assignment_id', $original->id)
                ->where('member.active_slot', 1)->whereNull('member.released_at')
                ->select('member.id', 'member.cleaning_booking_session_task_id')->lockForUpdate()->get();
            if ($memberships->isEmpty()
                || $session->workerAssignments()->where('worker_id', $request->candidate_worker_id)->exists()) {
                $this->deny('replacement_membership_unavailable');
            }
            $replacement = $session->workerAssignments()->create([
                'worker_id' => $request->candidate_worker_id,
                'status' => CleaningBookingWorkerAssignmentStatus::Accepted,
                'accepted_at' => $request->accepted_at,
            ]);
            foreach ($memberships as $membership) {
                DB::table('cleaning_booking_session_task_workers')->where('id', $membership->id)->update([
                    'active_slot' => null, 'released_at' => now(),
                    'release_reason' => 'Approved no-show replacement #'.$request->id,
                    'updated_at' => now(),
                ]);
                $session->executionTasks()->findOrFail($membership->cleaning_booking_session_task_id)->workers()->create([
                    'cleaning_booking_session_worker_assignment_id' => $replacement->id,
                    'allocated_by_supervisor_assignment_id' => $owner->id,
                    'allocated_by_user_id' => $supervisor->id,
                    'membership_source' => 'supervisor_explicit',
                    'allocated_at' => now(), 'active_slot' => 1,
                ]);
            }
            $original->update([
                'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                'released_at' => now(),
                'released_reason' => 'Approved no-show replacement #'.$request->id,
            ]);
            $request->update([
                'status' => 'reallocated', 'replacement_worker_assignment_id' => $replacement->id,
                'reallocated_by_user_id' => $supervisor->id, 'reallocated_at' => now(),
            ]);

            return $this->summary($session);
        }, 3);
    }

    /** @return array{CleaningBooking,CleaningBookingSession,CleaningBookingSessionReplacementRequest} */
    private function lockRequest(CleaningBooking $booking, CleaningBookingSession $session, int $requestId): array
    {
        $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $session = CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)
            ->whereKey($session->id)->lockForUpdate()->firstOrFail();
        $request = $session->replacementRequests()->whereKey($requestId)->lockForUpdate()->firstOrFail();

        return [$booking, $session, $request];
    }

    private function owner(CleaningBookingSession $session, User $actor): CleaningBookingSessionSupervisorAssignment
    {
        $owners = $session->supervisorAssignments()->where('active_slot', 1)
            ->whereNull('released_at')->get();
        if ($owners->count() !== 1 || (int) $owners->first()->supervisor_user_id !== (int) $actor->id) {
            abort(404, 'Visit not found.');
        }

        return $owners->first();
    }

    private function assertRequestable(CleaningBooking $booking, CleaningBookingSession $session, CleaningBookingSessionSupervisorAssignment $supervisor): void
    {
        if ((int) $session->cleaning_booking_id !== (int) $booking->id
            || ! $booking->isV2OperationalGroup()
            || ! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $session->isTerminal() || $session->work_started_at !== null
            || in_array($booking->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true)) {
            $this->deny('visit_not_replaceable');
        }
        if ((float) $supervisor->compensation_hourly_rate <= 0) {
            $this->deny('supervisor_compensation_unconfigured');
        }
        if (! $session->supervisorAttendances()
            ->where('cleaning_booking_session_supervisor_assignment_id', $supervisor->id)
            ->whereNotNull('arrived_at')->exists()) {
            $this->deny('supervisor_arrival_unverified');
        }
        $provenance = $this->provenance->evaluate($booking, $session);
        if (! $provenance['valid'] || $provenance['taskCandidates'] === []
            || ! $this->allocations->membershipProven($session, $provenance['taskCandidates'])) {
            $this->deny('task_membership_unproven');
        }
        if ($session->executionVerifications()->where('active_slot', 1)
            ->where('status', 'verified')->whereNotNull('consumed_at')->exists()
            || $session->supervisorWorkSegments()->exists()
            || DB::table('cleaning_booking_session_task_worker_segments as segment')
                ->join('cleaning_booking_session_task_workers as member', 'member.id', '=', 'segment.cleaning_booking_session_task_worker_id')
                ->join('cleaning_booking_session_tasks as task', 'task.id', '=', 'member.cleaning_booking_session_task_id')
                ->where('task.cleaning_booking_session_id', $session->id)->exists()) {
            $this->deny('visit_execution_already_started');
        }
    }

    /** @return array<string,mixed> */
    private function summary(CleaningBookingSession $session): array
    {
        $items = $session->replacementRequests()->orderBy('id')->get();

        return [
            'visitId' => (int) $session->id,
            'pendingRequests' => $items->where('status', 'pending')->count(),
            'requests' => $items->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'workerId' => (int) $session->workerAssignments()
                    ->whereKey($item->cleaning_booking_session_worker_assignment_id)->value('worker_id'),
                'status' => $item->status,
                'reason' => $item->reason,
                'requestedAt' => $item->created_at?->toIso8601String(),
                'approvedByUserId' => $item->approved_by_user_id,
                'approvedAt' => $item->approved_at?->toIso8601String(),
                'candidateWorkerId' => $item->candidate_worker_id,
                'acceptedAt' => $item->accepted_at?->toIso8601String(),
                'replacementWorkerAssignmentId' => $item->replacement_worker_assignment_id,
                'reallocatedAt' => $item->reallocated_at?->toIso8601String(),
            ])->all(),
            'replacementAssigned' => $items->contains('status', 'reallocated'),
            'workExecutionEnabled' => false,
            'settlementEnabled' => false,
        ];
    }

    private function deny(string $reason): never
    {
        throw ValidationException::withMessages(['replacement' => [$reason]]);
    }
}

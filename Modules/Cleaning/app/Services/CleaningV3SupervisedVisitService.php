<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus as VisitStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus as AssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningV3SupervisedVisitService
{
    private const TABLE = 'cleaning_v3_visit_verifications';
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CleaningV3OperationalAdmissionPolicy $admission,
        private readonly CleaningSupervisorAssignmentService $supervisors,
        private readonly CleaningBookingSessionLifecycleService $lifecycle,
    ) {}

    public function requestVerification(User $actor, int $bookingId, int $visitId): array
    {
        return DB::transaction(function () use ($actor, $bookingId, $visitId): array {
            [, $visit] = $this->supervised($actor, $bookingId, $visitId);
            $crew = $this->arrivedCrew($visit);
            $current = $this->verification($visitId);
            if ($this->matches($current, $crew, (int) $actor->id)) {
                if ($current->verified_at !== null && now()->lt($current->expires_at)) {
                    return ['status' => 'verified'];
                }
                if ($this->isPending($current)) {
                    return ['status' => 'pending', 'expiresAt' => CarbonImmutable::parse($current->expires_at)->toIso8601String()];
                }
                abort_if($current->attempts >= self::MAX_ATTEMPTS && now()->lt($current->expires_at), 409, 'V3_VISIT_CODE_ATTEMPTS_EXHAUSTED');
            }

            $code = (string) random_int(100000, 999999);
            $expires = now()->addMinutes(10);
            DB::table(self::TABLE)->upsert([[
                'visit_id' => $visitId,
                'supervisor_user_id' => $actor->id,
                'crew_digest' => $this->crewDigest($crew),
                'code_hash' => Hash::make($code),
                'code_ciphertext' => Crypt::encryptString($code),
                'attempts' => 0,
                'expires_at' => $expires,
                'verified_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['visit_id'], ['supervisor_user_id', 'crew_digest', 'code_hash', 'code_ciphertext',
                'attempts', 'expires_at', 'verified_at', 'updated_at']);

            return ['status' => 'pending', 'expiresAt' => $expires->toIso8601String()];
        }, 3);
    }

    public function customerCode(User $actor, int $bookingId, int $visitId): array
    {
        return DB::transaction(function () use ($actor, $bookingId, $visitId): array {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            abort_unless($actor->is_active && (int) $booking->customer_id === (int) $actor->id, 404);
            $current = $this->verification($visitId);
            abort_unless($this->isPending($current), 404);
            $crew = $this->arrivedCrew($visit);
            abort_unless($this->matches($current, $crew, $this->supervisors->activeSupervisorId($booking)), 404);

            return [
                'code' => Crypt::decryptString($current->code_ciphertext),
                'expiresAt' => CarbonImmutable::parse($current->expires_at)->toIso8601String(),
            ];
        }, 3);
    }

    public function confirmVerification(User $actor, int $bookingId, int $visitId, string $code): array
    {
        $verified = DB::transaction(function () use ($actor, $bookingId, $visitId, $code): bool {
            [, $visit] = $this->supervised($actor, $bookingId, $visitId);
            $crew = $this->arrivedCrew($visit);
            $current = $this->verification($visitId);
            if (! $this->matches($current, $crew, (int) $actor->id) || ! $this->isPending($current)) {
                return false;
            }
            if (! Hash::check($code, $current->code_hash)) {
                DB::table(self::TABLE)->where('visit_id', $visitId)->increment('attempts', 1, ['updated_at' => now()]);
                return false;
            }
            DB::table(self::TABLE)->where('visit_id', $visitId)->update([
                'verified_at' => now(), 'code_ciphertext' => null, 'updated_at' => now(),
            ]);

            return true;
        }, 3);
        abort_unless($verified, 422, 'INVALID_OR_EXPIRED_CODE');

        return ['status' => 'verified'];
    }

    public function start(User $actor, int $bookingId, int $visitId): array
    {
        return DB::transaction(function () use ($actor, $bookingId, $visitId): array {
            [$booking, $visit] = $this->supervised($actor, $bookingId, $visitId);
            if ($visit->status === VisitStatus::InProgress) {
                return $this->status($visit);
            }
            $crew = $this->arrivedCrew($visit);
            $current = $this->verification($visitId);
            abort_unless($this->matches($current, $crew, (int) $actor->id) && $current->verified_at !== null
                && now()->lt($current->expires_at), 409, 'V3_VISIT_VERIFICATION_REQUIRED');
            foreach ($crew as $assignment) {
                $assignment->forceFill(['start_approved_at' => $current->verified_at])->save();
                $this->lifecycle->startWork($booking, $visit, $assignment->worker, supervisorVerified: true);
            }

            return $this->status($visit->refresh());
        }, 3);
    }

    public function requestHandover(User $actor, int $bookingId, int $visitId, ?array $report = null): array
    {
        return DB::transaction(function () use ($actor, $bookingId, $visitId, $report): array {
            [$booking, $visit] = $this->supervised($actor, $bookingId, $visitId);
            if ($visit->status === VisitStatus::AwaitingCustomerCompletion) {
                return $this->status($visit);
            }
            abort_unless($visit->status === VisitStatus::InProgress, 409, 'V3_VISIT_NOT_IN_PROGRESS');
            $crew = $this->crew($visit);
            abort_unless($crew->count() === $visit->requiredWorkerCount()
                && $crew->every(fn ($assignment) => $assignment->work_started_at !== null && $assignment->worker !== null),
                409, 'V3_VISIT_CREW_NOT_READY');
            foreach ($crew as $assignment) {
                $this->lifecycle->requestCompletion($booking, $visit, $assignment->worker, supervisorVerified: true);
            }
            CleaningCustomerIncidentService::record($booking, $visitId, (int) $actor->id, 'supervisor', $report);

            return $this->status($visit->refresh());
        }, 3);
    }

    private function lock(int $bookingId, int $visitId): array
    {
        $booking = CleaningBooking::query()->lockForUpdate()->findOrFail($bookingId);
        $visit = $booking->sessions()->lockForUpdate()->findOrFail($visitId);
        abort_unless($booking->contract_version === 'v3'
            && $visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            && $this->admission->approved($booking, $visit), 404);

        return [$booking, $visit];
    }

    private function supervised(User $actor, int $bookingId, int $visitId): array
    {
        [$booking, $visit] = $this->lock($bookingId, $visitId);
        abort_unless($actor->is_active && $actor->hasRole(SupervisedTeamService::ROLE)
            && $this->supervisors->activeSupervisorId($booking) === (int) $actor->id, 404);

        return [$booking, $visit];
    }

    private function crew(CleaningBookingSession $visit): Collection
    {
        return $visit->workerAssignments()->whereNull('released_at')
            ->whereIn('status', AssignmentStatus::acceptedValues())->orderBy('id')->lockForUpdate()->get();
    }

    private function arrivedCrew(CleaningBookingSession $visit): Collection
    {
        abort_unless(in_array($visit->status, [VisitStatus::AwaitingStartVerification, VisitStatus::AwaitingWorkerStartConfirmation], true),
            409, 'V3_VISIT_NOT_AWAITING_VERIFICATION');
        $crew = $this->crew($visit);
        abort_unless($crew->count() === $visit->requiredWorkerCount()
            && $crew->every(fn ($assignment) => $assignment->arrived_at !== null && $assignment->worker !== null),
            409, 'V3_VISIT_CREW_NOT_READY');

        return $crew;
    }

    private function crewDigest(Collection $crew): string
    {
        return hash('sha256', json_encode($crew->map(fn ($assignment) => [
            $assignment->id, $assignment->worker_id, $assignment->accepted_at, $assignment->arrived_at,
        ])->all(), JSON_THROW_ON_ERROR));
    }

    private function verification(int $visitId): ?object
    {
        return DB::table(self::TABLE)->where('visit_id', $visitId)->lockForUpdate()->first();
    }

    public function verificationStatus(int $visitId): string
    {
        $visit = CleaningBookingSession::query()->find($visitId);
        if ($visit === null || ! in_array($visit->status, [VisitStatus::AwaitingStartVerification, VisitStatus::AwaitingWorkerStartConfirmation], true)) {
            return 'not_requested';
        }
        $crew = $visit->workerAssignments()->whereNull('released_at')
            ->whereIn('status', AssignmentStatus::acceptedValues())->orderBy('id')->get();
        $verification = DB::table(self::TABLE)->where('visit_id', $visitId)->first();
        if ($crew->count() !== $visit->requiredWorkerCount()
            || ! $crew->every(fn ($assignment) => $assignment->arrived_at !== null && $assignment->worker !== null)
            || ! $this->matches($verification, $crew, $this->supervisors->activeSupervisorId($visit->booking))) {
            return 'not_requested';
        }
        if ($verification !== null && $verification->verified_at !== null && now()->lt($verification->expires_at)) {
            return 'verified';
        }

        return $this->isPending($verification) ? 'pending' : 'not_requested';
    }

    /** Read-only counterpart of the execution-scoped supervisor write guards. */
    public function allowedActions(?User $actor, CleaningBooking $booking, CleaningBookingSession $visit, ?array $clock): array
    {
        if (! $actor instanceof User || ! $actor->is_active || ! $actor->hasRole(SupervisedTeamService::ROLE)
            || $booking->contract_version !== 'v3'
            || (int) $visit->cleaning_booking_id !== (int) $booking->id
            || $visit->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || ! $this->admission->approved($booking, $visit)
            || $this->supervisors->activeSupervisorId($booking) !== (int) $actor->id
            || $visit->isTerminal()) {
            return [];
        }
        $crew = $visit->workerAssignments()->whereNull('released_at')
            ->whereIn('status', AssignmentStatus::acceptedValues())->orderBy('id')->get();
        if ($crew->count() !== $visit->requiredWorkerCount()
            || ! $crew->every(fn ($assignment) => $assignment->worker !== null && $assignment->arrived_at !== null)) {
            return [];
        }
        if (in_array($visit->status, [VisitStatus::AwaitingStartVerification, VisitStatus::AwaitingWorkerStartConfirmation], true)) {
            return match ($this->verificationStatus((int) $visit->id)) {
                'verified' => ['start_work'],
                'pending' => ['confirm_verification'],
                default => ['request_verification'],
            };
        }
        if ($visit->status === VisitStatus::InProgress && ($clock['state'] ?? null) === 'running'
            && is_string($clock['activeSince'] ?? null)
            && CarbonImmutable::parse($clock['activeSince'])->lt(CarbonImmutable::parse($clock['serverNow']))
            && $crew->every(fn ($assignment) => $assignment->work_started_at !== null
                && in_array($assignment->status->value, AssignmentStatus::activeValues(), true))) {
            return ['request_handover'];
        }

        return [];
    }

    private function matches(?object $verification, Collection $crew, ?int $supervisorId): bool
    {
        return $verification !== null && $supervisorId === (int) $verification->supervisor_user_id
            && hash_equals($verification->crew_digest, $this->crewDigest($crew));
    }

    private function isPending(?object $verification): bool
    {
        return $verification !== null && $verification->verified_at === null
            && is_string($verification->code_ciphertext) && $verification->attempts < self::MAX_ATTEMPTS
            && now()->lt($verification->expires_at);
    }

    private function status(CleaningBookingSession $visit): array
    {
        return ['visitId' => (int) $visit->id, 'status' => $visit->status->value];
    }
}

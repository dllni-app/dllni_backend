<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningBookingSessionVerification;

/**
 * G2C-1: One-time, customer-private, supervisor-entered V2 visit verification.
 * This service never starts work, certifies attendance, or settles money.
 */
final class CleaningV2VisitVerificationService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CleaningV2VisitExecutionProvenanceService $provenance,
        private readonly CleaningV2VisitTaskAllocationService $memberships,
    ) {}

    /** @return array{status:string,expiresAt:string|null,verifiedAt:string|null,executionEnabled:bool} */
    public function request(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);

        return DB::transaction(function () use ($actor, $booking, $session): array {
            [$booking, $session] = $this->lockedVisit($booking, $session);
            $supervisor = $this->owner($session, $actor);
            $this->assertReady($booking, $session, $supervisor);

            $active = $session->executionVerifications()->where('active_slot', 1)->lockForUpdate()->get();
            if ($active->count() > 1) {
                $this->deny('verification_ownership_ambiguous');
            }
            $previous = $active->first();
            if ($previous !== null) {
                if ((int) $previous->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id
                    || (int) $previous->customer_user_id !== (int) $booking->customer_id) {
                    $this->deny('verification_owner_changed');
                }
                if ($previous->status === 'verified' && $previous->verified_at !== null
                    && $previous->consumed_at !== null) {
                    return $this->summary($previous);
                }
                if ($previous->status === 'pending' && $previous->expires_at->isFuture()
                    && $previous->attempts < $previous->max_attempts
                    && $previous->invalidated_at === null) {
                    return $this->summary($previous);
                }
                // Explicitly invalidate old code before a fresh challenge.
                $previous->forceFill([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                    'customer_code_ciphertext' => null,
                    'code_hash' => null,
                    'active_slot' => null,
                ])->save();
            }

            $code = (string) random_int(100000, 999999);
            $record = $session->executionVerifications()->create([
                'cleaning_booking_session_supervisor_assignment_id' => $supervisor->id,
                'customer_user_id' => $booking->customer_id,
                'code_hash' => Hash::make($code),
                'customer_code_ciphertext' => Crypt::encryptString($code),
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'expires_at' => now()->addMinutes(10),
                'active_slot' => 1,
            ]);

            return $this->summary($record);
        });
    }

    /** @return array{status:string,expiresAt:string|null,verifiedAt:string|null,executionEnabled:bool} */
    public function status(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        $supervisor = $this->owner($session, $actor);
        $canRequest = true;
        try {
            $this->assertReady($booking, $session, $supervisor);
        } catch (ValidationException $exception) {
            $canRequest = false;
        }
        $active = $session->executionVerifications()->where('active_slot', 1)->first();
        $status = $active === null ? ['status' => 'not_requested', 'expiresAt' => null,
            'verifiedAt' => null, 'executionEnabled' => false] : $this->summary($active);

        return [...$status, 'requestAvailable' => $canRequest && $status['status'] !== 'verified'];
    }

    /** @return array{code:string,expiresAt:string,purpose:string,visitSequence:int} */
    public function customerCode(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        if ((int) $booking->customer_id !== (int) $actor->id
            || (int) $session->cleaning_booking_id !== (int) $booking->id || ! $booking->isV2OperationalGroup()) {
            abort(404);
        }
        $active = $session->executionVerifications()->where('active_slot', 1)->first();
        if ($active === null || $active->status !== 'pending'
            || $active->expires_at->isPast() || $active->attempts >= $active->max_attempts
            || $active->invalidated_at !== null || $active->customer_code_ciphertext === null
            || (int) $active->customer_user_id !== (int) $actor->id
            || ! $session->supervisorAssignments()->whereKey($active->cleaning_booking_session_supervisor_assignment_id)
                ->where('active_slot', 1)->whereNull('released_at')->exists()) {
            abort(404);
        }

        // An earlier-issued code must not remain viewable after the team,
        // task membership or supervisor custody has become invalid.
        $supervisor = $session->activeSupervisorAssignment()->first();
        if ($supervisor === null || (int) $supervisor->id !== (int) $active->cleaning_booking_session_supervisor_assignment_id) {
            abort(404);
        }
        try {
            $this->assertReady($booking, $session, $supervisor);
        } catch (ValidationException $exception) {
            abort(404);
        }

        return [
            'code' => Crypt::decryptString($active->customer_code_ciphertext),
            'expiresAt' => $active->expires_at->toIso8601String(),
            'purpose' => 'v2_visit_supervisor_start_verification',
            'visitSequence' => (int) $session->sequence,
        ];
    }

    /** @return array{status:string,expiresAt:string|null,verifiedAt:string|null,executionEnabled:bool} */
    public function confirm(User $actor, CleaningBooking $booking, CleaningBookingSession $session, string $code): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        $result = DB::transaction(function () use ($actor, $booking, $session, $code): array {
            [$booking, $session] = $this->lockedVisit($booking, $session);
            $supervisor = $this->owner($session, $actor);
            $this->assertReady($booking, $session, $supervisor);
            $record = $session->executionVerifications()->where('active_slot', 1)->lockForUpdate()->first();
            if ($record === null || (int) $record->cleaning_booking_session_supervisor_assignment_id !== (int) $supervisor->id
                || (int) $record->customer_user_id !== (int) $booking->customer_id) {
                return ['error' => 'verification_not_requested'];
            }
            if ($record->status !== 'pending' || $record->expires_at->isPast()
                || $record->invalidated_at !== null || $record->attempts >= $record->max_attempts
                || $record->code_hash === null) {
                return ['error' => 'verification_not_pending'];
            }
            if (! Hash::check($code, $record->code_hash)) {
                $attempts = $record->attempts + 1;
                $record->forceFill([
                    'attempts' => $attempts,
                    ...($attempts >= $record->max_attempts ? [
                        'status' => 'invalidated',
                        'invalidated_at' => now(),
                        'customer_code_ciphertext' => null,
                        'code_hash' => null,
                        'active_slot' => null,
                    ] : []),
                ])->save();

                return ['error' => 'invalid_code'];
            }

            $record->forceFill([
                'status' => 'verified',
                'verified_at' => now(),
                'consumed_at' => now(),
                'customer_code_ciphertext' => null,
                'code_hash' => null,
            ])->save();

            return $this->summary($record);
        });

        if (isset($result['error'])) {
            $this->deny($result['error']);
        }

        return $result;
    }

    /** @return array{0:CleaningBooking,1:CleaningBookingSession} */
    private function lockedVisit(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $session = CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)
            ->whereKey($session->id)->lockForUpdate()->firstOrFail();

        return [$booking, $session];
    }

    private function owner(CleaningBookingSession $session, User $actor): CleaningBookingSessionSupervisorAssignment
    {
        $active = $session->supervisorAssignments()->where('active_slot', 1)->whereNull('released_at')->get();
        if ($active->count() !== 1 || (int) $active->first()->supervisor_user_id !== (int) $actor->id) {
            abort(404, 'Visit not found.');
        }

        return $active->first();
    }

    private function assertReady(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        CleaningBookingSessionSupervisorAssignment $supervisor,
    ): void {
        if (! $booking->isV2OperationalGroup() || ! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $session->isTerminal() || in_array($booking->status,
                [CleaningBookingStatus::Cancelled, CleaningBookingStatus::Completed], true)) {
            $this->deny('visit_not_eligible');
        }
        if ((float) $supervisor->compensation_hourly_rate <= 0) {
            $this->deny('compensation_unconfigured');
        }
        if (! $session->supervisorAttendances()->where('cleaning_booking_session_supervisor_assignment_id', $supervisor->id)
            ->whereNotNull('started_travel_at')->whereNotNull('arrived_at')->exists()) {
            $this->deny('supervisor_not_arrived');
        }
        $signed = $this->provenance->evaluate($booking, $session);
        if (! $signed['valid'] || $signed['taskCandidates'] === []
            || ! $this->memberships->membershipProven($session, $signed['taskCandidates'])) {
            $this->deny('task_membership_unproven');
        }
        $workers = $session->workerAssignments()->whereNull('released_at')->whereNotNull('accepted_at')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->get();
        if ($workers->count() !== $session->requiredWorkerCount()
            || $workers->pluck('worker_id')->unique()->count() !== $workers->count()
            || $workers->contains(fn ($worker): bool => $worker->started_travel_at === null || $worker->arrived_at === null)) {
            $this->deny('team_not_arrived');
        }
        if (! app(CleaningV2VisitWorkerAttestationService::class)->allPresent($session, $supervisor->id)) {
            $this->deny('actual_attendance_not_certified');
        }
    }

    /** @return array{status:string,expiresAt:string|null,verifiedAt:string|null,executionEnabled:bool} */
    private function summary(CleaningBookingSessionVerification $verification): array
    {
        return [
            'status' => $verification->status === 'pending' && $verification->expires_at->isPast()
                ? 'expired' : $verification->status,
            'expiresAt' => $verification->expires_at?->toIso8601String(),
            'verifiedAt' => $verification->verified_at?->toIso8601String(),
            'executionEnabled' => false,
        ];
    }

    private function deny(string $code): never
    {
        throw ValidationException::withMessages(['verification' => [$code]]);
    }
}

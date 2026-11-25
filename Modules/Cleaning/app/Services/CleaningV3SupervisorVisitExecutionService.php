<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus as SessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus as WorkerStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** The assigned supervisor alone verifies and controls the exact staffed visit. */
final class CleaningV3SupervisorVisitExecutionService
{
    private const TABLE = 'cleaning_v3_supervisor_visit_verifications';

    public function state(int $bookingId, int $visitId, User $actor): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor): array {
            [$booking, $visit, $supervisor] = $this->lock($bookingId, $visitId);
            $isCustomer = $this->customer($actor, $booking);
            abort_unless($isCustomer || $this->supervisor($actor, $supervisor), 404);
            return $this->project($visit, $supervisor, $isCustomer);
        }, 3);
    }

    public function issue(int $bookingId, int $visitId, User $actor): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor): array {
            [$booking, $visit, $supervisor] = $this->lock($bookingId, $visitId);
            abort_unless($this->customer($actor, $booking), 404);
            $roster = $this->readyRoster($visit);
            $digest = $this->digest($roster);
            $existing = DB::table(self::TABLE)->where('visit_id', $visitId)->lockForUpdate()->first();
            if ($existing && $existing->verified_at !== null) {
                throw new DomainException('V3_VISIT_ALREADY_VERIFIED');
            }
            if ($existing && (int) $existing->supervisor_user_id === $supervisor
                && hash_equals($existing->roster_digest, $digest)
                && now()->lt($existing->expires_at)) {
                if ($existing->attempts >= 5) {
                    throw new DomainException('V3_VISIT_CODE_ATTEMPTS_EXHAUSTED');
                }
                return ['code' => Crypt::decryptString($existing->code_ciphertext), 'expiresAt' => \Carbon\Carbon::parse($existing->expires_at)->toIso8601String()];
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = now()->addMinutes(10);
            DB::table(self::TABLE)->updateOrInsert(['visit_id' => $visitId], [
                'supervisor_user_id' => $supervisor, 'roster_digest' => $digest,
                'code_hash' => Hash::make($code), 'code_ciphertext' => Crypt::encryptString($code),
                'attempts' => 0, 'expires_at' => $expires, 'verified_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['code' => $code, 'expiresAt' => $expires->toIso8601String()];
        }, 3);
    }

    public function verify(int $bookingId, int $visitId, User $actor, string $code): array
    {
        $result = DB::transaction(function () use ($bookingId, $visitId, $actor, $code): array {
            [$booking, $visit, $supervisor] = $this->lock($bookingId, $visitId);
            abort_unless($this->supervisor($actor, $supervisor), 404);
            $roster = $this->readyRoster($visit);
            $row = DB::table(self::TABLE)->where('visit_id', $visitId)->lockForUpdate()->first();
            if ($row === null || $row->verified_at !== null || now()->gte($row->expires_at)
                || $row->attempts >= 5 || (int) $row->supervisor_user_id !== $supervisor
                || ! hash_equals($row->roster_digest, $this->digest($roster))) {
                throw new DomainException('V3_VISIT_CODE_UNAVAILABLE');
            }
            if (! Hash::check($code, $row->code_hash)) {
                DB::table(self::TABLE)->where('visit_id', $visitId)->increment('attempts');
                return ['error' => 'V3_VISIT_CODE_INVALID'];
            }
            $at = now();
            DB::table(self::TABLE)->where('visit_id', $visitId)->update([
                'verified_at' => $at, 'code_ciphertext' => null, 'updated_at' => $at,
            ]);
            $visit->workerAssignments()->whereIn('id', $roster->pluck('id'))->update([
                'status' => WorkerStatus::StartApproved->value, 'start_approved_at' => $at, 'updated_at' => $at,
            ]);
            $visit->forceFill(['status' => SessionStatus::AwaitingWorkerStartConfirmation, 'customer_confirmed_at' => $at])->saveQuietly();
            app(CleaningBookingSessionLifecycleService::class)->syncParentStatus($booking);
            return $this->project($visit, $supervisor, false);
        }, 3);
        if (isset($result['error'])) {
            throw new DomainException($result['error']);
        }
        return $result;
    }

    public function start(int $bookingId, int $visitId, User $actor): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor): array {
            [$booking, $visit, $supervisor] = $this->lock($bookingId, $visitId);
            abort_unless($this->supervisor($actor, $supervisor), 404);
            if ($visit->status === SessionStatus::InProgress) {
                return $this->project($visit, $supervisor, false);
            }
            $roster = $this->roster($visit);
            $row = DB::table(self::TABLE)->where('visit_id', $visitId)->lockForUpdate()->first();
            if ($visit->status !== SessionStatus::AwaitingWorkerStartConfirmation || $row === null
                || $row->verified_at === null || (int) $row->supervisor_user_id !== $supervisor
                || ! hash_equals($row->roster_digest, $this->digest($roster))
                || $roster->count() !== $visit->requiredWorkerCount()
                || $roster->contains(fn ($a) => $a->start_approved_at === null || $a->arrived_at === null || $a->status !== WorkerStatus::StartApproved)) {
                throw new DomainException('V3_SUPERVISOR_VISIT_START_NOT_READY');
            }
            app(CleaningV3OperationalAdmissionService::class)->assertVisitPayable($booking, $visit);
            app(CleaningV3EquipmentResumeGuard::class)->extend($visit, now('UTC')->toImmutable()->addMinutes((int) round($visit->duration_hours * 60)));
            $at = now();
            $visit->workerAssignments()->whereIn('id', $roster->pluck('id'))->update([
                'status' => WorkerStatus::InProgress->value, 'work_started_at' => $at, 'updated_at' => $at,
            ]);
            $visit->forceFill(['status' => SessionStatus::InProgress, 'work_started_at' => $at])->saveQuietly();
            app(CleaningV3WorkClockCommandService::class)->initializeApprovedVisit($bookingId, $visitId);
            app(CleaningBookingSessionLifecycleService::class)->syncParentStatus($booking);
            return $this->project($visit->fresh(), $supervisor, false);
        }, 3);
    }

    public function finish(int $bookingId, int $visitId, User $actor, ?array $report = null): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor, $report): array {
            [$booking, $visit, $supervisor] = $this->lock($bookingId, $visitId);
            abort_unless($this->supervisor($actor, $supervisor), 404);
            if (in_array($visit->status, [SessionStatus::AwaitingCustomerCompletion, SessionStatus::Completed], true)) {
                return $this->project($visit, $supervisor, false);
            }
            $roster = $this->roster($visit);
            if (! in_array($visit->status, [SessionStatus::InProgress, SessionStatus::Paused], true)
                || $roster->count() !== $visit->requiredWorkerCount()
                || $roster->contains(fn ($a) => $a->work_started_at === null || $a->status !== WorkerStatus::InProgress)) {
                throw new DomainException('V3_SUPERVISOR_VISIT_FINISH_NOT_READY');
            }
            app(CleaningV3WorkClockCommandService::class)->stopForApprovedCompletion($bookingId, $visitId);
            $at = now();
            $visit->workerAssignments()->whereIn('id', $roster->pluck('id'))->update([
                'status' => WorkerStatus::AwaitingCustomerCompletion->value, 'work_finished_at' => $at, 'updated_at' => $at,
            ]);
            $visit->forceFill(['status' => SessionStatus::AwaitingCustomerCompletion, 'work_finished_at' => $at, 'payment_status' => 'pending'])->saveQuietly();
            CleaningCustomerIncidentService::record($booking, $visitId, (int) $actor->id, 'supervisor', $report);
            app(CleaningBookingSessionLifecycleService::class)->syncParentStatus($booking);
            return $this->project($visit->fresh(), $supervisor, false);
        }, 3);
    }

    private function lock(int $bookingId, int $visitId): array
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        abort_unless(CleaningV3OperationalAdmissionService::admitted($booking), 404);
        $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        abort_unless($visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR, 404);
        $id = app(CleaningSupervisorAssignmentService::class)->activeSupervisorId($booking);
        abort_unless($id !== null && User::query()->whereKey($id)->where('is_active', true)->first()?->hasRole(SupervisedTeamService::ROLE), 404);
        return [$booking, $visit, $id];
    }

    private function customer(User $actor, CleaningBooking $booking): bool
    {
        return $actor->is_active && (int) $actor->id === (int) $booking->customer_id;
    }

    private function supervisor(User $actor, int $id): bool
    {
        return $actor->is_active && (int) $actor->id === $id && $actor->hasRole(SupervisedTeamService::ROLE);
    }

    private function roster(CleaningBookingSession $visit)
    {
        return $visit->workerAssignments()->whereNull('released_at')->whereIn('status', WorkerStatus::acceptedValues())
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function readyRoster(CleaningBookingSession $visit)
    {
        $roster = $this->roster($visit);
        if ($visit->status !== SessionStatus::AwaitingStartVerification || $roster->count() !== $visit->requiredWorkerCount()
            || $roster->contains(fn ($a) => $a->arrived_at === null || $a->status !== WorkerStatus::AwaitingStartVerification)) {
            throw new DomainException('V3_SUPERVISOR_VISIT_ARRIVAL_NOT_READY');
        }
        return $roster;
    }

    private function digest($roster): string
    {
        return hash('sha256', json_encode($roster->map(fn ($a) => [(int) $a->id, (int) $a->worker_id, (string) $a->accepted_at])->all(), JSON_THROW_ON_ERROR));
    }

    private function project(CleaningBookingSession $visit, int $supervisor, bool $customer): array
    {
        $roster = $this->roster($visit);
        $ready = $roster->count() === $visit->requiredWorkerCount() && $roster->every(fn ($a) => $a->arrived_at !== null);
        $workers = $roster->load('worker.user');
        return ['visitId' => (int) $visit->id, 'status' => $visit->status->value,
            'requiredWorkers' => $visit->requiredWorkerCount(), 'acceptedWorkers' => $roster->count(),
            'arrivedWorkers' => $roster->filter(fn ($a) => $a->arrived_at !== null)->count(),
            'canIssueCode' => $customer && $ready && $visit->status === SessionStatus::AwaitingStartVerification,
            'canVerify' => ! $customer && $ready && $visit->status === SessionStatus::AwaitingStartVerification,
            'canStart' => ! $customer && $visit->status === SessionStatus::AwaitingWorkerStartConfirmation,
            'canFinish' => ! $customer && in_array($visit->status, [SessionStatus::InProgress, SessionStatus::Paused], true),
            'workers' => $customer ? [] : $workers->map(fn ($a) => ['workerId' => (int) $a->worker_id,
                'name' => $a->worker?->user?->name, 'phone' => $a->worker?->user?->phone,
                'status' => $a->status->value, 'arrived' => $a->arrived_at !== null])->all()];
    }
}

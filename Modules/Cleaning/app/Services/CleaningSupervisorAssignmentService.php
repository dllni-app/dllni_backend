<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

/** The single assignment/transfer boundary shared by the API and the Filament booking page. */
final class CleaningSupervisorAssignmentService
{
    public function __construct(
        private readonly SupervisedTeamService $teams,
        private readonly SupervisorScheduledV2Service $scheduledV2,
    ) {}

    public function canAssign(User $actor, bool $fromDashboard = false): bool
    {
        // Filament may supply an authenticated User instance loaded with a
        // limited column selection. Re-check the active account in the DB;
        // also prevents stale in-memory auth state from authorizing a change.
        if (! $actor->exists || ! User::query()->whereKey($actor->getKey())->where('is_active', true)->exists()) {
            return false;
        }

        if (! $fromDashboard) {
            // Retain the existing API permission contract.
            return $actor->hasAnyRole(['Super Admin', 'Cleaning Ops Manager']);
        }

        // Filament's admin role can edit bookings; operations managers must
        // also have the normal booking update permission. Support agents with
        // bookings.update alone cannot manage another person's custody.
        return $actor->hasAnyRole(['admin', 'Super Admin'])
            || ($actor->hasRole('Cleaning Ops Manager') && $actor->can('bookings.update'));
    }

    public function isEligible(CleaningBooking $booking): bool
    {
        return $booking->isSupervisedTeam() || $this->scheduledV2->isEligible($booking);
    }

    public function activeSupervisorId(CleaningBooking $booking): ?int
    {
        $id = $booking->supervisorAssignments()->where('active_slot', 1)
            ->whereNull('released_at')->value('supervisor_user_id');

        return $id === null ? null : (int) $id;
    }

    public function activeVisitSupervisorId(CleaningBookingSession $session): ?int
    {
        $id = $session->activeSupervisorAssignment()->value('supervisor_user_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{id:int,supervisorUserId:int,unchanged:bool} */
    public function assign(
        User $actor,
        int $bookingId,
        int $supervisorUserId,
        ?string $reason,
        bool $isV2,
        bool $fromDashboard = false,
    ): array {
        abort_unless($this->canAssign($actor, $fromDashboard), 403);

        if ($isV2) {
            return $this->assignV2Booking($actor, $bookingId, $supervisorUserId, $reason);
        }

        return DB::transaction(function () use ($actor, $bookingId, $supervisorUserId, $reason): array {
            $order = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            abort_unless($order->isSupervisedTeam(), 404);
            abort_if(in_array($order->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);

            // Serialize assignment attempts on the supervisor principal as well
            // as the booking, so concurrent bookings cannot claim one interval.
            $supervisor = User::query()->whereKey($supervisorUserId)->lockForUpdate()->firstOrFail();
            abort_unless($supervisor->is_active && $supervisor->hasRole(SupervisedTeamService::ROLE), 422);

            $existing = DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $bookingId)
                ->where('active_slot', 1)->whereNull('released_at')->first();
            if ($existing && (int) $existing->supervisor_user_id === (int) $supervisor->id) {
                return ['id' => (int) $existing->id, 'supervisorUserId' => (int) $supervisor->id, 'unchanged' => true];
            }

            $reason = $reason !== null ? mb_trim($reason) : null;
            if ($existing && ($reason === null || mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
                throw ValidationException::withMessages([
                    'reason' => ['Supervisor reassignment requires a recorded reason of 10–500 characters.'],
                ]);
            }

            if ($existing) {
                $hasUnreturnedEquipment = CleaningEquipmentReservation::query()
                    ->where('supervisor_user_id', $existing->supervisor_user_id)
                    ->whereNull('return_confirmed_at')
                    ->whereIn('status', [
                        'reserved', 'handed_over', 'acknowledged',
                        'return_pending_confirmation', 'failure_pending_confirmation',
                    ])
                    ->where(function ($query) use ($bookingId): void {
                        $query->where('cleaning_booking_id', $bookingId)
                            ->orWhereHas('bookingSpecialService', fn ($service) => $service->where('cleaning_booking_id', $bookingId));
                    })->exists();
                abort_if($hasUnreturnedEquipment || $order->materialKit()->whereNotNull('received_at')->exists(), 409,
                    'يجب إغلاق عهدة المواد والمعدات قبل تغيير المشرف.');
            }

            abort_if($order->supervised_state === 'in_progress', 409,
                'إعادة إسناد الطلب الجاري تحتاج مسار استثناء معتمد.');

            $this->scheduledV2->assertNoOverlap($order, $supervisor);

            if ($existing) {
                DB::table('cleaning_supervisor_assignments')->where('id', $existing->id)->update([
                    'active_slot' => null, 'released_at' => now(),
                    'reason' => $reason, 'updated_at' => now(),
                ]);
            }

            $id = DB::table('cleaning_supervisor_assignments')->insertGetId([
                'cleaning_booking_id' => $bookingId,
                'supervisor_user_id' => $supervisor->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->teams->activity($bookingId, $actor->id, $existing ? 'supervisor_reassigned' : 'supervisor_assigned', [
                'assignmentId' => $id,
                'supervisorUserId' => $supervisor->id,
                'previousSupervisorUserId' => $existing?->supervisor_user_id,
            ]);

            return ['id' => $id, 'supervisorUserId' => (int) $supervisor->id, 'unchanged' => false];
        }, 3);
    }

    /** @return array{id:int,supervisorUserId:int,unchanged:bool,visitSequence:int} */
    public function assignVisit(
        User $actor,
        int $bookingId,
        int $visitSequence,
        int $supervisorUserId,
        ?string $reason,
        bool $fromDashboard = false,
    ): array {
        abort_unless($this->canAssign($actor, $fromDashboard), 403);

        return DB::transaction(function () use ($actor, $bookingId, $visitSequence, $supervisorUserId, $reason): array {
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $supervisor = User::query()->whereKey($supervisorUserId)->lockForUpdate()->firstOrFail();
            $this->assertAssignableSupervisor($supervisor);
            abort_if(in_array($booking->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);
            abort_unless($this->scheduledV2->isEligible($booking), 404);
            $session = $booking->sessions()->where('sequence', $visitSequence)->lockForUpdate()->firstOrFail();

            return $this->assignVisitLocked($actor, $booking, $session, $supervisor, $reason);
        }, 3);
    }

    /** @return array{id:int,supervisorUserId:int,unchanged:bool,visitAssignmentCount:int} */
    private function assignV2Booking(
        User $actor,
        int $bookingId,
        int $supervisorUserId,
        ?string $reason,
    ): array {
        return DB::transaction(function () use ($actor, $bookingId, $supervisorUserId, $reason): array {
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $supervisor = User::query()->whereKey($supervisorUserId)->lockForUpdate()->firstOrFail();
            $this->assertAssignableSupervisor($supervisor);
            abort_if(in_array($booking->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);
            abort_unless($this->scheduledV2->isEligible($booking), 404);

            $sessions = $booking->sessions()->orderBy('id')->lockForUpdate()->get();
            abort_if($sessions->isEmpty(), 409, 'لا توجد زيارات مهيأة لإسناد المشرف.');
            abort_if($sessions->contains(fn (CleaningBookingSession $session): bool => ! $session->isSupervisionInitialized()
                || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR), 409,
                'يجب تهيئة ملكية جميع الزيارات قبل الإسناد الجماعي.');

            $eligible = $sessions->filter(fn (CleaningBookingSession $session): bool => $this->isFutureUnstartedVisit(
                $session,
                ! $session->activeSupervisorAssignment()->exists(),
            ));
            abort_if($eligible->isEmpty(), 409, 'لا توجد زيارات مستقبلية غير مبدوءة قابلة للإسناد.');

            $legacy = DB::table('cleaning_supervisor_assignments')
                ->where('cleaning_booking_id', $booking->id)
                ->where('active_slot', 1)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();
            $trimmedReason = $this->normalizedReason($reason);
            if ($legacy && (int) $legacy->supervisor_user_id !== (int) $supervisor->id) {
                $this->assertReassignmentReason($trimmedReason);
                $this->assertNoBookingCustody($booking, (int) $legacy->supervisor_user_id);
            }

            $results = [];
            foreach ($eligible as $session) {
                $results[] = $this->assignVisitLocked($actor, $booking, $session, $supervisor, $trimmedReason);
            }

            if ($legacy) {
                DB::table('cleaning_supervisor_assignments')->where('id', $legacy->id)->update([
                    'active_slot' => null,
                    'released_at' => now(),
                    'reason' => $trimmedReason,
                    'updated_at' => now(),
                ]);
            }

            $first = $results[0];

            return [
                'id' => $first['id'],
                'supervisorUserId' => (int) $supervisor->id,
                'unchanged' => $legacy === null && collect($results)->every(fn (array $result): bool => $result['unchanged']),
                'visitAssignmentCount' => count($results),
            ];
        }, 3);
    }

    /** @return array{id:int,supervisorUserId:int,unchanged:bool,visitSequence:int} */
    private function assignVisitLocked(
        User $actor,
        CleaningBooking $booking,
        CleaningBookingSession $session,
        User $supervisor,
        ?string $reason,
    ): array {
        abort_unless((int) $session->cleaning_booking_id === (int) $booking->id, 404);
        abort_if(! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR, 409,
            'الزيارة غير مهيأة لملكية المشرف.');
        $existing = $session->supervisorAssignments()
            ->where('active_slot', 1)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->first();
        abort_if(! $this->isFutureUnstartedVisit($session, $existing === null), 409,
            'يمكن إسناد الزيارات المستقبلية غير المبدوءة فقط.');
        $this->assertAssignableSupervisor($supervisor);
        if ($existing && (int) $existing->supervisor_user_id === (int) $supervisor->id) {
            return [
                'id' => (int) $existing->id,
                'supervisorUserId' => (int) $supervisor->id,
                'unchanged' => true,
                'visitSequence' => (int) $session->sequence,
            ];
        }

        $trimmedReason = $this->normalizedReason($reason);
        if ($existing) {
            $this->assertReassignmentReason($trimmedReason);
            $this->assertNoBookingCustody($booking, (int) $existing->supervisor_user_id);
        }

        $this->assertNoVisitOverlap($session, $supervisor);

        if ($existing) {
            $existing->forceFill([
                'active_slot' => null,
                'released_at' => now(),
                'reason' => $trimmedReason,
            ])->save();
        }

        // The compensation agreed when the customer booked the visit is the
        // durable rate. Changing Financial Settings after booking must not
        // silently alter an already quoted visit's eventual supervisor share.
        // Legacy visits without this new private snapshot retain the former
        // current-setting behavior, without rewriting historical assignments.
        $bookedRate = data_get($session->pricing_snapshot, 'supervisorCompensationHourlyRate');
        $rate = max(0.0, (float) (
            is_numeric($bookedRate)
                ? $bookedRate
                : CleaningRuntimeSettings::financial()->supervisor_compensation_hourly_rate
        ));
        $assignment = CleaningBookingSessionSupervisorAssignment::query()->create([
            'cleaning_booking_session_id' => $session->id,
            'supervisor_user_id' => $supervisor->id,
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now(),
            'compensation_hourly_rate' => $rate,
            'active_slot' => 1,
        ]);

        $this->teams->activity($booking->id, $actor->id, $existing ? 'visit_supervisor_reassigned' : 'visit_supervisor_assigned', [
            'visitAssignmentId' => $assignment->id,
            'visitSequence' => (int) $session->sequence,
            'supervisorUserId' => (int) $supervisor->id,
            'previousSupervisorUserId' => $existing?->supervisor_user_id,
            'compensationHourlyRate' => $rate,
        ]);

        return [
            'id' => (int) $assignment->id,
            'supervisorUserId' => (int) $supervisor->id,
            'unchanged' => false,
            'visitSequence' => (int) $session->sequence,
        ];
    }

    private function assertAssignableSupervisor(User $supervisor): void
    {
        abort_unless($supervisor->is_active && $supervisor->hasRole(SupervisedTeamService::ROLE), 422);
    }

    private function isFutureUnstartedVisit(CleaningBookingSession $session, bool $allowAcceptedWorkers = false): bool
    {
        $status = (string) ($session->status?->value ?? $session->status);
        if (! in_array($status, [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)
            || $session->started_travel_at !== null
            || $session->arrived_at !== null
            || $session->work_started_at !== null
            || $session->work_finished_at !== null
            || $session->workerAssignments()->where(function ($assignments) use ($allowAcceptedWorkers): void {
                $assignments->whereNotNull('started_travel_at')
                    ->orWhereNotNull('arrived_at')
                    ->orWhereNotNull('work_started_at')
                    ->orWhereNotNull('work_finished_at');
                if (! $allowAcceptedWorkers) {
                    $assignments->orWhereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                        ->orWhereNotNull('accepted_at');
                }
            })->exists()) {
            return false;
        }

        if ((string) $session->session_type === CleaningBookingSession::TYPE_OPEN_TIME) {
            $start = $this->scheduledV2->openTimeInterval($session)[0] ?? null;
        } else {
            $windows = $this->visitIntervals($session);
            $start = $windows === []
                ? null
                : collect($windows)->map(static fn (array $window): CarbonImmutable => $window[0])->min();
        }

        return $start instanceof CarbonImmutable && $start->greaterThan(now(config('app.timezone')));
    }

    private function normalizedReason(?string $reason): ?string
    {
        $trimmed = $reason !== null ? mb_trim($reason) : null;

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertReassignmentReason(?string $reason): void
    {
        if ($reason === null || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => ['Supervisor reassignment requires a recorded reason of 10–500 characters.'],
            ]);
        }
    }

    private function assertNoBookingCustody(CleaningBooking $booking, int $supervisorUserId): void
    {
        $hasUnreturnedEquipment = CleaningEquipmentReservation::query()
            ->where('supervisor_user_id', $supervisorUserId)
            ->whereNull('return_confirmed_at')
            ->whereIn('status', [
                'reserved', 'handed_over', 'acknowledged',
                'return_pending_confirmation', 'failure_pending_confirmation',
            ])
            ->where(function ($query) use ($booking): void {
                $query->where('cleaning_booking_id', $booking->id)
                    ->orWhereHas('bookingSpecialService', fn ($service) => $service->where('cleaning_booking_id', $booking->id));
            })->exists();

        abort_if($hasUnreturnedEquipment || $booking->materialKit()->whereNotNull('received_at')->exists(), 409,
            'يجب إغلاق عهدة المواد والمعدات قبل تغيير المشرف.');
    }

    private function assertNoVisitOverlap(CleaningBookingSession $targetSession, User $supervisor): void
    {
        $target = $this->visitIntervals($targetSession);
        abort_if($target === [], 422, 'لا يوجد جدول صالح للزيارة.');

        $assignedVisits = CleaningBookingSessionSupervisorAssignment::query()
            ->where('supervisor_user_id', $supervisor->id)
            ->where('active_slot', 1)
            ->whereNull('released_at')
            ->where('cleaning_booking_session_id', '<>', $targetSession->id)
            ->with('session.booking')
            ->get();
        foreach ($assignedVisits as $assignment) {
            $otherSession = $assignment->session;
            if (! $otherSession instanceof CleaningBookingSession) {
                continue;
            }
            $this->assertIntervalsDoNotOverlap($target, $this->visitIntervals($otherSession));
        }

        $legacyBookings = CleaningBooking::query()
            ->whereKeyNot($targetSession->cleaning_booking_id)
            ->whereNotIn('status', [CleaningBookingStatus::Completed->value, CleaningBookingStatus::Cancelled->value])
            ->whereHas('supervisorAssignments', fn ($query) => $query
                ->where('supervisor_user_id', $supervisor->id)
                ->where('active_slot', 1)
                ->whereNull('released_at'))
            ->get();
        foreach ($legacyBookings as $booking) {
            $this->assertIntervalsDoNotOverlap($target, $this->scheduledV2->intervals($booking));
        }
    }

    /** @return list<array{0:CarbonImmutable,1:CarbonImmutable}> */
    private function visitIntervals(CleaningBookingSession $session): array
    {
        $windows = [];
        $booking = $session->booking;
        if (! $booking instanceof CleaningBooking) {
            return [];
        }

        foreach ($booking->roomSchedules()->where('cleaning_booking_session_id', $session->id)->get() as $schedule) {
            if ($schedule->starts_at === null || $schedule->ends_at === null || $schedule->ends_at->lessThanOrEqualTo($schedule->starts_at)) {
                return [];
            }
            $windows[] = [CarbonImmutable::instance($schedule->starts_at), CarbonImmutable::instance($schedule->ends_at)];
        }
        foreach ($booking->roomPeriods()->where('cleaning_booking_session_id', $session->id)->get() as $period) {
            if ($period->scheduled_date === null || (int) $period->estimated_duration_minutes <= 0) {
                return [];
            }
            $start = CarbonImmutable::parse(
                $period->scheduled_date->format('Y-m-d').' '.$period->scheduled_time,
                config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
            );
            $windows[] = [$start, $start->addMinutes((int) $period->estimated_duration_minutes)];
        }

        if ($windows === [] && (string) $session->session_type === CleaningBookingSession::TYPE_OPEN_TIME) {
            $openTime = $this->scheduledV2->openTimeInterval($session);

            return $openTime !== null ? [$openTime] : [];
        }

        return $windows;
    }

    /**
     * @param list<array{0:CarbonImmutable,1:CarbonImmutable}> $target
     * @param list<array{0:CarbonImmutable,1:CarbonImmutable}> $existing
     */
    private function assertIntervalsDoNotOverlap(array $target, array $existing): void
    {
        foreach ($existing as [$otherStart, $otherEnd]) {
            foreach ($target as [$start, $end]) {
                abort_if($start->lt($otherEnd) && $otherStart->lt($end), 409, 'المشرف لديه حجز متداخل.');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Documentary presence evidence, independently checked against actual visit time. */
final class CleaningV3SupervisorAttendanceProofService
{
    private const TABLE = 'cleaning_v3_supervisor_attendance_proofs';

    public function record(int $bookingId, int $visitId, int $supervisorId, string $from, string $until,
        string $reference, string $evidence): array
    {
        $this->authorize();
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $reference) !== 1
            || mb_strlen(trim($evidence)) < 10 || mb_strlen($evidence) > 500) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_EVIDENCE_REQUIRED');
        }
        $start = $this->parse($from);
        $end = $this->parse($until);
        $actor = (int) auth()->id();
        return DB::transaction(function () use ($bookingId, $visitId, $supervisorId, $start, $end, $reference, $evidence, $actor) {
            $visit = $this->lockVisit($bookingId, $visitId);
            $facts = $this->facts($visit, $supervisorId, $start, $end);
            $existing = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visitId)
                ->where('supervisor_assignment_id', $facts['supervisor_assignment_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->reference !== $reference || $existing->evidence !== $evidence
                    || (int) $existing->recorded_by !== $actor || ! $this->matches($existing, $facts)) {
                    throw new DomainException('V3_SUPERVISOR_ATTENDANCE_CONFLICT');
                }
                return $facts;
            }
            DB::table(self::TABLE)->insert([
                'cleaning_booking_session_id' => $visitId, ...$facts, 'reference' => $reference,
                'evidence' => $evidence, 'recorded_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $facts;
        }, 3);
    }

    public function verify(int $bookingId, int $visitId, string $reference, int $activeSeconds): array
    {
        $this->authorize();
        return DB::transaction(function () use ($bookingId, $visitId, $reference, $activeSeconds) {
            $visit = $this->lockVisit($bookingId, $visitId);
            $row = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visitId)
                ->where('reference', $reference)->lockForUpdate()->first();
            $actor = (int) auth()->id();
            if ($row === null || $row->reference !== $reference || (int) $row->recorded_by === $actor
                || (int) $row->active_seconds !== $activeSeconds) {
                throw new DomainException('V3_SUPERVISOR_ATTENDANCE_INDEPENDENT_VERIFICATION_REQUIRED');
            }
            $facts = $this->facts($visit, (int) $row->supervisor_user_id,
                CarbonImmutable::parse($row->present_from, 'UTC'), CarbonImmutable::parse($row->present_until, 'UTC'));
            if (! $this->matches($row, $facts)) {
                throw new DomainException('V3_SUPERVISOR_ATTENDANCE_CHANGED');
            }
            if ($row->verified_at === null) {
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'verified_by' => $actor, 'verified_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $facts;
        }, 3);
    }

    /** Must be called under the locked settlement visit transaction. */
    public function verified(CleaningBookingSession $visit): array
    {
        $rows = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
        $intervals = DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->orderBy('started_at')->lockForUpdate()->get();
        $needed = [];
        foreach ($visit->booking->supervisorAssignments()->lockForUpdate()->get() as $assignment) {
            foreach ($intervals as $interval) {
                if ($assignment->assigned_at < CarbonImmutable::parse($interval->ended_at, 'UTC')
                    && ($assignment->released_at === null || $assignment->released_at > CarbonImmutable::parse($interval->started_at, 'UTC'))) {
                    $needed[(int) $assignment->id] = true;
                    break;
                }
            }
        }
        if ($needed === [] || $rows->count() !== count($needed)) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
        }
        $proofs = [];
        $end = null;
        foreach ($rows->sortBy('present_from') as $row) {
            if (! isset($needed[(int) $row->supervisor_assignment_id]) || $row->verified_at === null
                || $row->verified_by === null || (int) $row->recorded_by === (int) $row->verified_by
                || ($end !== null && $row->present_from < $end)) {
                throw new DomainException('V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
            }
            $facts = $this->facts($visit, (int) $row->supervisor_user_id,
                CarbonImmutable::parse($row->present_from, 'UTC'), CarbonImmutable::parse($row->present_until, 'UTC'));
            if (! $this->matches($row, $facts)) {
                throw new DomainException('V3_SUPERVISOR_ATTENDANCE_CHANGED');
            }
            $proofs[] = $facts + ['proofReference' => $row->reference];
            $end = $row->present_until;
        }
        return $proofs;
    }

    private function authorize(): void
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        if (! config('cleaning_v3_catalog.cash_settlement_enabled', false)) {
            throw new DomainException('V3_CASH_SETTLEMENT_PAUSED');
        }
    }

    private function lockVisit(int $bookingId, int $visitId): CleaningBookingSession
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        if ($booking->contract_version !== 'v3' || $visit->status !== CleaningBookingSessionStatus::Completed
            || $visit->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $visit->customer_completed_at === null || $visit->payment_status === 'settled') {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_VISIT_REQUIRED');
        }
        $visit->setRelation('booking', $booking);
        return $visit;
    }

    private function parse(string $value): CarbonImmutable
    {
        // Explicit UTC only: civil time or ambiguous timezone input is rejected.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_UTC_REQUIRED');
        }
        try {
            $at = CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_UTC_REQUIRED');
        }
        if ($at->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_UTC_REQUIRED');
        }
        return $at;
    }

    private function facts(CleaningBookingSession $visit, int $supervisorId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($end <= $start || $end > CarbonImmutable::now('UTC')) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_TIME_INVALID');
        }
        $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
        if ($clock['state'] !== 'completed' || $clock['activeSince'] !== null || $clock['pausePending']) {
            throw new DomainException('V3_SETTLEMENT_CLOCK_NOT_CLOSED');
        }
        $intervals = DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)
            ->orderBy('started_at')->lockForUpdate()->get();
        if ($intervals->isEmpty()) {
            throw new DomainException('V3_SETTLEMENT_CLOCK_NOT_CLOSED');
        }
        $first = CarbonImmutable::parse($intervals->first()->started_at, 'UTC');
        $last = CarbonImmutable::parse($intervals->last()->ended_at, 'UTC');
        // Each replacement has its own evidence and assignment window.
        $assignments = $visit->booking->supervisorAssignments()
            ->where('supervisor_user_id', $supervisorId)
            ->where('assigned_at', '<=', $start->toDateTimeString())
            ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '>=', $end->toDateTimeString()))
            ->lockForUpdate()->get();
        $assignment = $assignments->count() === 1 ? $assignments->first() : null;
        if ($assignment === null || $start >= $last || $end <= $first) {
            throw new DomainException('V3_SUPERVISOR_ASSIGNMENT_REVIEW_REQUIRED');
        }
        // The actual documented attendance window must lie within the
        // assignment window; only intersection with active time is billable.
        if ($start < CarbonImmutable::instance($assignment->assigned_at)->utc()
            || ($assignment->released_at !== null && $end > CarbonImmutable::instance($assignment->released_at)->utc())) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_TIME_INVALID');
        }
        $seconds = 0;
        foreach ($intervals as $interval) {
            $a = CarbonImmutable::parse($interval->started_at, 'UTC')->max($start);
            $b = CarbonImmutable::parse($interval->ended_at, 'UTC')->min($end);
            if ($b > $a) {
                $seconds += (int) $a->diffInSeconds($b, false);
            }
        }
        if ($seconds <= 0 || $seconds > $clock['activeSeconds']) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_TIME_INVALID');
        }
        return [
            'supervisor_user_id' => $supervisorId, 'supervisor_assignment_id' => (int) $assignment->id,
            'clock_revision' => $clock['revision'], 'present_from' => $start->toDateTimeString(),
            'present_until' => $end->toDateTimeString(), 'active_seconds' => $seconds,
        ];
    }

    private function matches(object $row, array $facts): bool
    {
        foreach ($facts as $key => $value) {
            if ((string) $row->{$key} !== (string) $value) {
                return false;
            }
        }
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;

/** Read-only supervision for scheduled V2 contracts; execution remains session-owned. */
final class SupervisorScheduledV2Service
{
    public function isEligible(CleaningBooking $booking): bool
    {
        if ((int) $booking->number_of_workers <= 1) {
            return false;
        }

        if ((string) $booking->booking_kind === 'open_time') {
            $sessions = $booking->sessions()->get();

            return $sessions->isNotEmpty()
                && $sessions->every(fn (CleaningBookingSession $session): bool => $this->openTimeInterval($session) !== null);
        }

        $details = $booking->property_details;

        return (string) $booking->booking_kind === 'standard'
            && is_array($details)
            && is_array($details['_supervisor_pricing'] ?? null)
            && ($booking->roomSchedules()->exists() || $booking->roomPeriods()->exists());
    }

    public function assignedQuery(User $user, ?string $date = null): Builder
    {
        app(SupervisedTeamService::class)->assertSupervisor($user);

        $candidates = CleaningBooking::query()->whereIn('booking_kind', ['standard', 'open_time'])
            ->where('number_of_workers', '>', 1)
            ->where(function (Builder $query) use ($user, $date): void {
                $query->whereHas('sessions', function (Builder $sessions) use ($user, $date): void {
                    $sessions->whereHas('supervisorAssignments', fn (Builder $assignments): Builder => $assignments
                        ->where('supervisor_user_id', $user->id)
                        ->where('active_slot', 1)
                        ->whereNull('released_at'));
                    if ($date !== null) {
                        $sessions->whereDate('scheduled_date', $date);
                    }
                })
                    ->orWhere(function (Builder $legacy) use ($user, $date): void {
                        $legacy->whereDoesntHave('sessions', fn (Builder $sessions): Builder => $sessions
                            ->where(function (Builder $ownership): void {
                                $ownership->whereNotNull('supervision_initialized_at')
                                    ->orWhereNotNull('supervision_mode');
                            }))
                            ->whereDoesntHave('sessions.supervisorAssignments')
                            ->whereHas('supervisorAssignments', fn (Builder $assignments): Builder => $assignments
                                ->where('supervisor_user_id', $user->id)
                                ->where('active_slot', 1)
                                ->whereNull('released_at'));
                        if ($date !== null) {
                            $legacy->where(function (Builder $scheduled) use ($date): void {
                                $scheduled->whereHas('sessions', fn (Builder $sessions): Builder => $sessions
                                    ->whereDate('scheduled_date', $date))
                                    ->orWhereHas('roomPeriods', fn (Builder $periods): Builder => $periods
                                        ->whereDate('scheduled_date', $date));
                            });
                        }
                    });
            })
            ->get()
            ->filter(fn (CleaningBooking $booking): bool => $this->isEligible($booking))
            ->modelKeys();

        return CleaningBooking::query()->whereKey($candidates);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable}|null */
    public function openTimeInterval(CleaningBookingSession $session): ?array
    {
        $booking = $session->booking;
        if (! $booking instanceof CleaningBooking
            || (string) $booking->booking_kind !== 'open_time'
            || (int) $booking->number_of_workers <= 1
            || (string) $session->session_type !== CleaningBookingSession::TYPE_OPEN_TIME
            || ! $session->isSupervisionInitialized()
            || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $session->requiredWorkerCount() !== (int) $booking->number_of_workers) {
            return null;
        }

        $expectedMinutes = (int) $session->open_time_expected_max_minutes;
        $snapshot = $session->pricing_snapshot;
        $date = $session->scheduled_date?->toDateString();
        $time = mb_trim((string) $session->scheduled_time);
        $start = $date !== null && $time !== ''
            ? CarbonImmutable::parse(
                $date.' '.$time,
                config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
            )
            : null;
        if ($expectedMinutes <= 0
            || ! is_array($snapshot)
            || (string) ($snapshot['scheduleType'] ?? '') !== CleaningBookingSession::TYPE_OPEN_TIME
            || (int) ($snapshot['workerCount'] ?? 0) !== (int) $booking->number_of_workers
            || (int) ($snapshot['expectedMaxMinutes'] ?? 0) !== $expectedMinutes
            || ! $start instanceof CarbonImmutable) {
            return null;
        }

        return [$start, $start->addMinutes($expectedMinutes)];
    }

    public function assignedSessions(User $user, CleaningBooking $booking): HasMany
    {
        $sessions = $booking->sessions();
        if ($this->hasInitializedVisitOwnership($booking)) {
            return $sessions->whereHas('supervisorAssignments', fn (Builder $assignments): Builder => $assignments
                ->where('supervisor_user_id', $user->id)
                ->where('active_slot', 1)
                ->whereNull('released_at'));
        }

        return $sessions;
    }

    public function hasInitializedVisitOwnership(CleaningBooking $booking): bool
    {
        return $booking->sessions()
            ->where(function (Builder $ownership): void {
                $ownership->whereNotNull('supervision_initialized_at')
                    ->orWhereNotNull('supervision_mode');
            })
            ->exists();
    }

    public function assignedBooking(User $user, int $id): CleaningBooking
    {
        $booking = $this->assignedQuery($user)->find($id);

        if (! $booking || ! $this->isEligible($booking)) {
            throw new HttpResponseException(response()->json([
                'code' => 'BOOKING_NOT_FOUND', 'message' => 'الطلب غير موجود.',
            ], 404));
        }

        return $booking;
    }

    /**
     * Schedule windows must cover every visit, not just the booking header date.
     *
     * @return list<array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    public function intervals(CleaningBooking $booking): array
    {
        $windows = [];
        if ($booking->roomSchedules()->exists()) {
            foreach ($booking->roomSchedules()->get() as $schedule) {
                if ($schedule->starts_at === null || $schedule->ends_at === null || $schedule->ends_at->lessThanOrEqualTo($schedule->starts_at)) {
                    return [];
                }
                $windows[] = [CarbonImmutable::instance($schedule->starts_at), CarbonImmutable::instance($schedule->ends_at)];
            }
        }

        if ($booking->roomPeriods()->exists()) {
            foreach ($booking->roomPeriods()->get() as $period) {
                if ((int) $period->estimated_duration_minutes <= 0) {
                    return [];
                }
                $start = CarbonImmutable::parse($period->scheduled_date->format('Y-m-d').' '.$period->scheduled_time, config('cleaning_booking_v2.timezone', 'Asia/Damascus'));
                $windows[] = [$start, $start->addMinutes((int) $period->estimated_duration_minutes)];
            }
        }

        if ($windows !== []) {
            return $windows;
        }

        if (! $booking->isSupervisedTeam() || ! $booking->scheduled_date || ! $booking->scheduled_time) {
            return [];
        }

        $start = CarbonImmutable::parse($booking->scheduled_date->format('Y-m-d').' '.$booking->scheduled_time, config('cleaning_booking_v2.timezone', 'Asia/Damascus'));
        $duration = max(1, (int) ($booking->supervised_duration_minutes ?: ceil(
            ((float) $booking->estimated_hours * 60) / max(1, (int) $booking->number_of_workers)
        )));

        return [[$start, $start->addMinutes($duration)]];
    }

    public function assertNoOverlap(CleaningBooking $booking, User $supervisor): void
    {
        $target = $this->intervals($booking);
        abort_if($target === [], 422, 'لا يوجد جدول زيارات صالح لإسناد المشرف.');

        $others = CleaningBooking::query()->whereKeyNot($booking->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereIn('id', DB::table('cleaning_supervisor_assignments')
                ->where('supervisor_user_id', $supervisor->id)
                ->where('active_slot', 1)->whereNull('released_at')
                ->select('cleaning_booking_id'))
            ->get();

        foreach ($others as $other) {
            $existing = $this->intervals($other);
            // Historic unscheduled group custody records can exist without a
            // schedulable visit. They reserve inventory, not a calendar slot.
            foreach ($existing as [$otherStart, $otherEnd]) {
                foreach ($target as [$start, $end]) {
                    abort_if($start->lt($otherEnd) && $otherStart->lt($end), 409, 'المشرف لديه حجز متداخل.');
                }
            }
        }

        $visitAssignments = CleaningBookingSessionSupervisorAssignment::query()
            ->where('supervisor_user_id', $supervisor->id)
            ->where('active_slot', 1)
            ->whereNull('released_at')
            ->whereHas('session', fn (Builder $sessions): Builder => $sessions
                ->where('cleaning_booking_id', '<>', $booking->id))
            ->with('session.booking')
            ->get();

        foreach ($visitAssignments as $assignment) {
            $session = $assignment->session;
            if (! $session instanceof CleaningBookingSession) {
                continue;
            }

            foreach ($this->sessionIntervals($session) as [$otherStart, $otherEnd]) {
                foreach ($target as [$start, $end]) {
                    abort_if($start->lt($otherEnd) && $otherStart->lt($end), 409, 'المشرف لديه حجز متداخل.');
                }
            }
        }
    }

    /** @return list<array{0:CarbonImmutable,1:CarbonImmutable}> */
    private function sessionIntervals(CleaningBookingSession $session): array
    {
        $booking = $session->booking;
        if (! $booking instanceof CleaningBooking) {
            return [];
        }

        $windows = [];
        foreach ($booking->roomSchedules()->where('cleaning_booking_session_id', $session->id)->get() as $schedule) {
            if ($schedule->starts_at === null
                || $schedule->ends_at === null
                || $schedule->ends_at->lessThanOrEqualTo($schedule->starts_at)) {
                return [];
            }
            $windows[] = [CarbonImmutable::instance($schedule->starts_at), CarbonImmutable::instance($schedule->ends_at)];
        }

        foreach ($session->roomPeriods()->get() as $period) {
            if ($period->scheduled_date === null || (int) $period->estimated_duration_minutes <= 0) {
                return [];
            }
            $start = CarbonImmutable::parse(
                $period->scheduled_date->format('Y-m-d').' '.$period->scheduled_time,
                config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
            );
            $windows[] = [$start, $start->addMinutes((int) $period->estimated_duration_minutes)];
        }

        if ($windows !== []) {
            return $windows;
        }

        if ((string) $session->session_type === CleaningBookingSession::TYPE_OPEN_TIME) {
            $openTime = $this->openTimeInterval($session);

            return $openTime !== null ? [$openTime] : [];
        }

        $start = $session->startsAt();
        $end = $session->endsAt();

        return $start instanceof CarbonImmutable && $end instanceof CarbonImmutable && $end->greaterThan($start)
            ? [[$start, $end]]
            : [];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Booking-level supervisor scheduling and conflict prevention. */
final class CleaningSupervisorBookingScheduleService
{
    public function isEligible(CleaningBooking $booking): bool
    {
        if ((int) $booking->number_of_workers <= 1) {
            return false;
        }

        if ((string) $booking->booking_kind === 'open_time') {
            $sessions = $booking->sessions()->get();

            return $sessions->isNotEmpty()
                && $sessions->every(fn (CleaningBookingSession $session): bool => $session->startsAt() !== null && $session->endsAt() !== null);
        }

        return (string) $booking->booking_kind === 'standard'
            && $booking->scheduled_date !== null
            && $booking->scheduled_time !== null
            && (float) $booking->estimated_hours > 0;
    }

    /**
     * Schedule windows belong to each independent booking.
     *
     * @return list<array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    public function intervals(CleaningBooking $booking): array
    {
        $windows = [];
        if ((string) $booking->booking_kind === 'open_time') {
            foreach ($booking->sessions()->get() as $session) {
                $start = $session->startsAt();
                $end = $session->endsAt();
                if ($start !== null && $end !== null && $end->greaterThan($start)) {
                    $windows[] = [$start, $end];
                }
            }

            return $windows;
        }

        if (! in_array((string) $booking->booking_kind, ['standard', 'supervised_team'], true)
            || ! $booking->scheduled_date || ! $booking->scheduled_time) {
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
        abort_if($target === [], 422, 'لا يوجد جدول صالح لإسناد المشرف.');

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
            // schedulable booking. They reserve inventory, not a calendar slot.
            foreach ($existing as [$otherStart, $otherEnd]) {
                foreach ($target as [$start, $end]) {
                    abort_if($start->lt($otherEnd) && $otherStart->lt($end), 409, 'المشرف لديه حجز متداخل.');
                }
            }
        }

    }
}

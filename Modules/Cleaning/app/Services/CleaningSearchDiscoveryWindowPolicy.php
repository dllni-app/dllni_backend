<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Throwable;

/**
 * Six hours is a notification/escalation threshold, not an offer expiry.
 * Only real future starts with available seats may be offered or accepted.
 */
final class CleaningSearchDiscoveryWindowPolicy
{
    public function __construct(private readonly string $timezone = 'Asia/Damascus') {}

    /** Pure local-clock safety check, independent of DB/Eloquent. */
    public function isFutureLocalStart(?string $date, ?string $time, ?CarbonInterface $now = null): bool
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! is_string($time)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) {
            return false;
        }

        $time = mb_strlen($time) === 5 ? $time.':00' : $time;
        $literal = "$date $time";
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $literal, $this->timezone);
            if (! $start instanceof CarbonImmutable || $start->format('Y-m-d H:i:s') !== $literal) {
                return false;
            }

            $clock = $now === null
                ? CarbonImmutable::now($this->timezone)
                : CarbonImmutable::instance($now)->setTimezone($this->timezone);

            return $start->greaterThan($clock);
        } catch (Throwable) {
            return false;
        }
    }

    public function isFutureBookingStart(CleaningBooking $booking, ?CarbonInterface $now = null): bool
    {
        return $this->isFutureLocalStart(
            $booking->scheduled_date?->format('Y-m-d'),
            $booking->scheduled_time !== null ? (string) $booking->scheduled_time : null,
            $now,
        );
    }

    public function hasFutureOpenVisit(CleaningBooking $booking, ?CarbonInterface $now = null): bool
    {
        $clock = $now === null
            ? CarbonImmutable::now($this->timezone)
            : CarbonImmutable::instance($now)->setTimezone($this->timezone);

        return $booking->sessions()
            ->whereIn('status', [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
            ])
            ->get()
            ->contains(static fn (CleaningBookingSession $session): bool => $session->startsAt()?->greaterThan($clock) === true
                && $session->remainingWorkerCount() > 0);
    }

    public function hasAcceptableFutureStart(CleaningBooking $booking, ?CarbonInterface $now = null): bool
    {
        // An earlier completed/expired sibling does not prevent later visits
        // being offered, provided the later seats pass their own eligibility.
        if ($booking->sessions()->exists()) {
            return $this->hasFutureOpenVisit($booking, $now);
        }

        return $this->isFutureBookingStart($booking, $now);
    }
}

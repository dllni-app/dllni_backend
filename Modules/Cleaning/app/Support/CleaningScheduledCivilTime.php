<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A cleaning visit's separate scheduled_date and scheduled_time are civil
 * wall-clock values in the service area, not UTC instants. UTC is reserved
 * for explicit timestamp/ISO fields persisted on room schedules.
 */
final class CleaningScheduledCivilTime
{
    public static function parse(string $date, string $time): ?CarbonImmutable
    {
        $date = mb_trim($date);
        $time = mb_trim($time);
        if ($date === '' || $time === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $date.' '.$time,
                (string) config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
            );
        } catch (Throwable) {
            return null;
        }
    }
}

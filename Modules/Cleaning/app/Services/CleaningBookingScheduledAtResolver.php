<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Cleaning\Models\CleaningBooking;
use Throwable;

final class CleaningBookingScheduledAtResolver
{
    public function resolve(CleaningBooking $booking): ?CarbonImmutable
    {
        $date = $booking->scheduled_date;
        $time = is_string($booking->scheduled_time) ? trim($booking->scheduled_time) : '';

        if ($date === null || $time === '') {
            return null;
        }

        $timezone = (string) config('cleaning_action_notifications.timezone', config('app.timezone'));

        try {
            $dateValue = $date instanceof CarbonInterface
                ? $date->format('Y-m-d')
                : mb_substr((string) $date, 0, 10);

            return CarbonImmutable::parse("{$dateValue} {$time}", $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}

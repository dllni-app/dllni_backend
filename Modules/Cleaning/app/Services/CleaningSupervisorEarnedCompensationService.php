<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;

/** Computes earned compensation from closed work segments only. */
final class CleaningSupervisorEarnedCompensationService
{
    /** @return array{status:string,minutes:int,hourlyRate:string,earnedAmount:?string,reasonCodes:list<string>} */
    public function assess(CleaningBookingSessionSupervisorAssignment $assignment): array
    {
        $rate = (string) $assignment->getRawOriginal('compensation_hourly_rate');
        $reasons = [];
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $rate) || (float) $rate <= 0) {
            $reasons[] = 'supervisor_hourly_rate_invalid';
        }

        $segments = $assignment->workSegments()->orderBy('started_at')->get();
        $ranges = [];
        foreach ($segments as $segment) {
            if ($segment->started_at === null || $segment->ended_at === null || $segment->active_slot !== null) {
                $reasons[] = 'supervisor_work_segment_unclosed';

                continue;
            }
            $start = CarbonImmutable::instance($segment->started_at)->getTimestamp();
            $end = CarbonImmutable::instance($segment->ended_at)->getTimestamp();
            if ($end <= $start || (int) $segment->cleaning_booking_session_id !== (int) $assignment->cleaning_booking_session_id) {
                $reasons[] = 'supervisor_work_segment_invalid';

                continue;
            }
            $ranges[] = [$start, $end];
        }

        if ($ranges === []) {
            $reasons[] = 'supervisor_work_evidence_missing';
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $seconds = 0;
        $current = null;
        foreach ($ranges as [$start, $end]) {
            if ($current === null) {
                $current = [$start, $end];
            } elseif ($start > $current[1]) {
                $seconds += $current[1] - $current[0];
                $current = [$start, $end];
            } else {
                $current[1] = max($current[1], $end);
            }
        }
        if ($current !== null) {
            $seconds += $current[1] - $current[0];
        }

        $minutes = (int) ceil($seconds / 60);

        return [
            'status' => $reasons === [] ? 'calculated' : 'blocked',
            'minutes' => $minutes,
            'hourlyRate' => $rate,
            'earnedAmount' => $reasons === [] ? bcdiv(bcadd(bcdiv(bcmul($rate, (string) $minutes, 4), '60', 4), '0.005', 4), '1', 2) : null,
            'reasonCodes' => array_values(array_unique($reasons)),
        ];
    }
}

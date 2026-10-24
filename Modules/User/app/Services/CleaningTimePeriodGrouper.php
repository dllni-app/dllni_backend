<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;

final class CleaningTimePeriodGrouper
{
    /** Exact interval groups within one visit; intervals are half-open. */
    public function group(array $schedules): array
    {
        $groups = [];
        foreach ($schedules as $schedule) {
            $key = $schedule['startAt'].'|'.$schedule['endAt'];
            $groups[$key]['startAt'] = $schedule['startAt'];
            $groups[$key]['endAt'] = $schedule['endAt'];
            $groups[$key]['roomScheduleKeys'][] = $schedule['scheduleKey'];
        }
        foreach ($groups as &$group) {
            sort($group['roomScheduleKeys'], SORT_STRING);
            $group['periodKey'] = 'period.'.substr(hash('sha256', implode('|', $group['roomScheduleKeys'])), 0, 16);
        }
        unset($group);
        usort($groups, static fn (array $a, array $b): int => strcmp($a['startAt'], $b['startAt']) ?: strcmp($a['endAt'], $b['endAt']));

        return array_values($groups);
    }

    public function peakWorkers(array $schedules): int
    {
        $events = [];
        foreach ($schedules as $schedule) {
            $workers = (int) $schedule['numberOfWorkers'];
            $events[] = [CarbonImmutable::parse($schedule['startAt'])->timestamp, $workers];
            $events[] = [CarbonImmutable::parse($schedule['endAt'])->timestamp, -$workers];
        }
        usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $current = $peak = 0;
        foreach ($events as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak;
    }
}

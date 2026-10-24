<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

/** One supervisor's scheduled time is billed once across concurrent workers. */
final class CleaningSupervisorHourlyPricing
{
    /** @return array{hourlyRate:float,billableMinutes:int,hours:float,fee:float} */
    public function forMinutes(int $minutes): array
    {
        $minutes = max(0, $minutes);
        $hourlyRate = max(0.0, (float) (CleaningRuntimeSettings::financial()->supervisor_hourly_rate ?? 0));

        return [
            'hourlyRate' => $hourlyRate,
            'billableMinutes' => $minutes,
            'hours' => round($minutes / 60, 4),
            'fee' => app(CleaningPricingCalculator::class)->roundMoney($hourlyRate * $minutes / 60),
        ];
    }

    /**
     * Sum the union of room work intervals. Overlapping rooms share one
     * supervisor. Separate visits and gaps charge only minutes actually covered.
     *
     * @param array<int, array{startAt:string,endAt:string}> $schedules
     */
    public function unionMinutes(array $schedules): int
    {
        $ranges = array_map(static fn (array $schedule): array => [
            CarbonImmutable::parse($schedule['startAt'])->timestamp,
            CarbonImmutable::parse($schedule['endAt'])->timestamp,
        ], $schedules);
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $totalSeconds = 0;
        $current = null;
        foreach ($ranges as [$start, $end]) {
            if ($current === null) {
                $current = [$start, $end];

                continue;
            }
            if ($start > $current[1]) {
                $totalSeconds += max(0, $current[1] - $current[0]);
                $current = [$start, $end];
            } else {
                $current[1] = max($current[1], $end);
            }
        }
        if ($current !== null) {
            $totalSeconds += max(0, $current[1] - $current[0]);
        }

        return (int) ceil($totalSeconds / 60);
    }
}

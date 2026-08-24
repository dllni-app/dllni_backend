<?php

declare(strict_types=1);

namespace Modules\User\Support;

final class CleaningWorkerCapacity
{
    public const MAX_HOURS_PER_WORKER = 8.0;

    public static function requiredWorkers(float $estimatedHours): int
    {
        if ($estimatedHours <= 0) {
            return 1;
        }

        return max(1, (int) ceil($estimatedHours / self::MAX_HOURS_PER_WORKER));
    }

    /**
     * @return array{requiredWorkers:int,maxHoursPerWorker:float}
     */
    public static function payload(float $estimatedHours): array
    {
        return [
            'requiredWorkers' => self::requiredWorkers($estimatedHours),
            'maxHoursPerWorker' => self::MAX_HOURS_PER_WORKER,
        ];
    }
}

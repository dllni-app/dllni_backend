<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBooking;

final class SupervisedTeamScheduleService
{
    /**
     * No per-worker room schedule: one team start/end, with a separate preserved original end.
     * All base minutes come from the existing normal cleaning estimator.
     *
     * @return array{baseMinutes:int,durationMinutes:int,scheduledStartAt:string,scheduledEndAt:string}
     */
    public function estimate(int $baseMinutes, int $workerCount, string $date, string $time): array
    {
        $minimum = (int) config('cleaning_supervisor.minimum_workers', 2);
        $maximum = (int) config('cleaning_supervisor.maximum_workers', 20);
        if ($baseMinutes < 1 || $workerCount < $minimum || $workerCount > $maximum) {
            throw new \InvalidArgumentException('Invalid supervised team duration or worker count.');
        }

        $duration = (int) ceil($baseMinutes / $workerCount);
        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date.' '.$time,
            (string) config('app.timezone'),
        );
        if (! $start || $start->format('Y-m-d H:i') !== $date.' '.$time) {
            throw new \InvalidArgumentException('Invalid supervised team appointment.');
        }

        return [
            'baseMinutes' => $baseMinutes,
            'durationMinutes' => $duration,
            'scheduledStartAt' => $start->toIso8601String(),
            'scheduledEndAt' => $start->addMinutes($duration)->toIso8601String(),
        ];
    }

    /** @param array{baseMinutes:int,durationMinutes:int,scheduledStartAt:string,scheduledEndAt:string} $data */
    public function initialize(CleaningBooking $booking, array $data): CleaningBooking
    {
        if ((string) $booking->booking_kind !== SupervisedTeamService::KIND) {
            throw new \InvalidArgumentException('Only supervised team bookings can be initialized.');
        }

        $booking->forceFill([
            'supervised_state' => 'staffing',
            'supervised_base_minutes' => $data['baseMinutes'],
            'supervised_duration_minutes' => $data['durationMinutes'],
            'supervised_scheduled_end_at' => $data['scheduledEndAt'],
            'supervised_original_end_at' => $data['scheduledEndAt'],
        ])->save();

        return $booking;
    }
}

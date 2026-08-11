<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Throwable;

final class WorkerBookingScheduleConflictService
{
    /**
     * @var array<int, array<int, array{bookingId: int, start: CarbonImmutable, end: CarbonImmutable}>>
     */
    private array $busyIntervalsByWorker = [];

    public function hasConflict(Worker $worker, CleaningBooking $candidate): bool
    {
        $candidateInterval = $this->intervalFor($candidate);

        if ($candidateInterval === null) {
            return false;
        }

        foreach ($this->busyIntervalsFor($worker) as $busyInterval) {
            if ($busyInterval['bookingId'] === (int) $candidate->id) {
                continue;
            }

            if (
                $candidateInterval['start']->lt($busyInterval['end'])
                && $candidateInterval['end']->gt($busyInterval['start'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{bookingId: int, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function busyIntervalsFor(Worker $worker): array
    {
        $workerId = (int) $worker->id;

        if (array_key_exists($workerId, $this->busyIntervalsByWorker)) {
            return $this->busyIntervalsByWorker[$workerId];
        }

        $bookings = CleaningBooking::query()
            ->whereNotIn('status', [
                CleaningBookingStatus::Completed->value,
                CleaningBookingStatus::Cancelled->value,
            ])
            ->where(function (Builder $assigned) use ($workerId): void {
                $assigned
                    ->where(function (Builder $directAssignment) use ($workerId): void {
                        $directAssignment
                            ->where('worker_id', $workerId)
                            ->where('status', '!=', CleaningBookingStatus::Pending->value);
                    })
                    ->orWhereHas('workerAssignments', function (Builder $workerAssignments) use ($workerId): void {
                        $workerAssignments
                            ->where('worker_id', $workerId)
                            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues());
                    });
            })
            ->get([
                'id',
                'scheduled_date',
                'scheduled_time',
                'total_hours',
                'estimated_hours',
            ]);

        $intervals = [];

        foreach ($bookings as $booking) {
            $interval = $this->intervalFor($booking);

            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return $this->busyIntervalsByWorker[$workerId] = $intervals;
    }

    /**
     * @return array{bookingId: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function intervalFor(CleaningBooking $booking): ?array
    {
        $date = $booking->scheduled_date instanceof CarbonInterface
            ? $booking->scheduled_date->toDateString()
            : trim((string) $booking->scheduled_date);
        $time = trim((string) $booking->scheduled_time);

        if ($date === '' || $time === '') {
            return null;
        }

        try {
            $start = CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        $durationHours = (float) ($booking->total_hours ?? 0);

        if ($durationHours <= 0) {
            $durationHours = (float) ($booking->estimated_hours ?? 0);
        }

        $durationMinutes = max(1, (int) ceil(max($durationHours, 1.0) * 60));

        return [
            'bookingId' => (int) $booking->id,
            'start' => $start,
            'end' => $start->addMinutes($durationMinutes),
        ];
    }
}

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
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Support\WorkerAssignmentHoursResolver;
use Throwable;

final class WorkerBookingScheduleConflictService
{
    /**
     * @var array<int, array<int, array{bookingId: int, start: CarbonImmutable, end: CarbonImmutable}>>
     */
    private array $busyIntervalsByWorker = [];

    public function hasConflict(Worker $worker, CleaningBooking $candidate): bool
    {
        $candidateInterval = $this->intervalFor($candidate, $worker);

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
            ->with([
                'rooms',
                'workerAssignments' => function ($query) use ($workerId): void {
                    $query->where('worker_id', $workerId);
                },
            ])
            ->get([
                'id',
                'scheduled_date',
                'scheduled_time',
                'total_hours',
                'estimated_hours',
                'number_of_workers',
            ]);

        $intervals = [];

        foreach ($bookings as $booking) {
            $interval = $this->intervalFor($booking, $worker);

            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return $this->busyIntervalsByWorker[$workerId] = $intervals;
    }

    /**
     * @return array{bookingId: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function intervalFor(CleaningBooking $booking, Worker $worker): ?array
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

        $assignment = $this->assignmentForWorker($booking, $worker);
        $durationHours = WorkerAssignmentHoursResolver::resolve(
            $booking,
            $assignment,
            $booking->relationLoaded('rooms') ? $booking->rooms : null,
        );

        $durationMinutes = max(1, (int) ceil(max($durationHours, 1.0) * 60));

        return [
            'bookingId' => (int) $booking->id,
            'start' => $start,
            'end' => $start->addMinutes($durationMinutes),
        ];
    }

    private function assignmentForWorker(CleaningBooking $booking, Worker $worker): ?CleaningBookingWorkerAssignment
    {
        if ($booking->relationLoaded('workerAssignments')) {
            $assignment = $booking->workerAssignments->firstWhere('worker_id', $worker->id);

            return $assignment instanceof CleaningBookingWorkerAssignment ? $assignment : null;
        }

        $assignment = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('worker_id', $worker->id)
            ->first();

        return $assignment instanceof CleaningBookingWorkerAssignment ? $assignment : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Throwable;

final class WorkerBookingScheduleConflictService
{
    /**
     * @var array<int, array<int, array{bookingId:int,sessionId:?int,date:string,start:CarbonImmutable,end:CarbonImmutable}>>
     */
    private array $busyIntervalsByWorker = [];

    public function hasConflict(Worker $worker, CleaningBooking $candidate): bool
    {
        return $this->conflictsForBooking($worker, $candidate) !== [];
    }

    /** @return array<int, array{sessionId:?int,date:string,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}> */
    public function conflictsForBooking(Worker $worker, CleaningBooking $candidate): array
    {
        return $this->conflictsForIntervals(
            $worker,
            $this->intervalsForBooking($candidate),
            (int) ($candidate->id ?? 0),
        );
    }

    /**
     * @param array<int, array{date:string,time:string,hours:float|int}> $definitions
     */
    public function hasConflictForDefinitions(Worker $worker, array $definitions, ?int $excludeBookingId = null): bool
    {
        return $this->conflictsForDefinitions($worker, $definitions, $excludeBookingId) !== [];
    }

    /**
     * @param array<int, array{date:string,time:string,hours:float|int}> $definitions
     * @return array<int, array{sessionId:?int,date:string,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}>
     */
    public function conflictsForDefinitions(Worker $worker, array $definitions, ?int $excludeBookingId = null): array
    {
        $intervals = [];

        foreach ($definitions as $definition) {
            $interval = $this->intervalFromParts(
                (string) ($definition['date'] ?? ''),
                (string) ($definition['time'] ?? ''),
                (float) ($definition['hours'] ?? 0),
                $excludeBookingId ?? 0,
                null,
            );

            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return $this->conflictsForIntervals($worker, $intervals, $excludeBookingId ?? 0);
    }

    /**
     * @param array<int, array{bookingId:int,sessionId:?int,date:string,start:CarbonImmutable,end:CarbonImmutable}> $candidateIntervals
     * @return array<int, array{sessionId:?int,date:string,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}>
     */
    private function conflictsForIntervals(Worker $worker, array $candidateIntervals, int $excludeBookingId): array
    {
        $conflicts = [];

        foreach ($candidateIntervals as $candidate) {
            foreach ($this->busyIntervalsFor($worker) as $busy) {
                if ($excludeBookingId > 0 && $busy['bookingId'] === $excludeBookingId) {
                    continue;
                }

                if ($candidate['start']->lt($busy['end']) && $candidate['end']->gt($busy['start'])) {
                    $conflicts[] = [
                        'sessionId' => $candidate['sessionId'],
                        'date' => $candidate['date'],
                        'start' => $candidate['start']->format('H:i'),
                        'end' => $candidate['end']->format('H:i'),
                        'conflictingBookingId' => $busy['bookingId'],
                        'conflictingSessionId' => $busy['sessionId'],
                    ];
                    break;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return array<int, array{bookingId:int,sessionId:?int,date:string,start:CarbonImmutable,end:CarbonImmutable}>
     */
    private function busyIntervalsFor(Worker $worker): array
    {
        $workerId = (int) $worker->id;

        if (array_key_exists($workerId, $this->busyIntervalsByWorker)) {
            return $this->busyIntervalsByWorker[$workerId];
        }

        $bookings = CleaningBooking::query()
            ->with('sessions')
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
                            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                    });
            })
            ->get();

        $intervals = [];
        foreach ($bookings as $booking) {
            foreach ($this->intervalsForBooking($booking) as $interval) {
                $intervals[] = $interval;
            }
        }

        return $this->busyIntervalsByWorker[$workerId] = $intervals;
    }

    /** @return array<int, array{bookingId:int,sessionId:?int,date:string,start:CarbonImmutable,end:CarbonImmutable}> */
    private function intervalsForBooking(CleaningBooking $booking): array
    {
        $booking->loadMissing('sessions');

        if ($booking->sessions->isNotEmpty()) {
            return $booking->sessions
                ->filter(fn (CleaningBookingSession $session): bool => ! in_array($session->status, [
                    CleaningBookingSessionStatus::Completed,
                    CleaningBookingSessionStatus::Cancelled,
                ], true))
                ->map(function (CleaningBookingSession $session) use ($booking): ?array {
                    return $this->intervalFromParts(
                        $session->scheduled_date?->toDateString() ?? '',
                        (string) $session->scheduled_time,
                        (float) $session->duration_hours,
                        (int) $booking->id,
                        (int) $session->id,
                    );
                })
                ->filter()
                ->values()
                ->all();
        }

        $date = $booking->scheduled_date instanceof CarbonInterface
            ? $booking->scheduled_date->toDateString()
            : trim((string) $booking->scheduled_date);
        $durationHours = (float) ($booking->total_hours ?? 0);
        if ($durationHours <= 0) {
            $durationHours = (float) ($booking->estimated_hours ?? 0);
        }

        $interval = $this->intervalFromParts(
            $date,
            (string) $booking->scheduled_time,
            $durationHours,
            (int) ($booking->id ?? 0),
            null,
        );

        return $interval !== null ? [$interval] : [];
    }

    /** @return array{bookingId:int,sessionId:?int,date:string,start:CarbonImmutable,end:CarbonImmutable}|null */
    private function intervalFromParts(string $date, string $time, float $durationHours, int $bookingId, ?int $sessionId): ?array
    {
        $date = mb_trim($date);
        $time = mb_trim($time);
        if ($date === '' || $time === '') {
            return null;
        }

        try {
            $start = CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        $durationMinutes = max(1, (int) ceil(max($durationHours, 1.0) * 60));

        return [
            'bookingId' => $bookingId,
            'sessionId' => $sessionId,
            'date' => $date,
            'start' => $start,
            'end' => $start->addMinutes($durationMinutes),
        ];
    }
}

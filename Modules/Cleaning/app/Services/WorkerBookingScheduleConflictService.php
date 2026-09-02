<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Throwable;

final class WorkerBookingScheduleConflictService
{
    /**
     * @var array<int, array<int, array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}>>
     */
    private array $busyIntervalsByWorker = [];

    public function hasConflict(Worker $worker, CleaningBooking $candidate): bool
    {
        return $this->conflictsForBooking($worker, $candidate) !== [];
    }

    /**
     * @return array<int, array{sessionId:?int,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}>
     */
    public function conflictsForBooking(Worker $worker, CleaningBooking $candidate): array
    {
        return $this->conflictsForIntervals(
            $worker,
            $this->intervalsForBooking($candidate),
            (int) ($candidate->id ?? 0),
            null,
        );
    }

    public function hasConflictForSession(Worker $worker, CleaningBookingSession $candidate): bool
    {
        return $this->conflictsForSession($worker, $candidate) !== [];
    }

    /**
     * @return array<int, array{sessionId:?int,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}>
     */
    public function conflictsForSession(Worker $worker, CleaningBookingSession $candidate): array
    {
        $interval = $this->intervalForSession($candidate);

        if ($interval === null) {
            return [];
        }

        return $this->conflictsForIntervals(
            $worker,
            [$interval],
            (int) $candidate->cleaning_booking_id,
            (int) $candidate->id,
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
     * @return array<int, array{sessionId:?int,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}>
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

        return $this->conflictsForIntervals($worker, $intervals, $excludeBookingId ?? 0, null);
    }

    public function forgetWorker(Worker|int $worker): void
    {
        $workerId = $worker instanceof Worker ? (int) $worker->id : (int) $worker;
        unset($this->busyIntervalsByWorker[$workerId]);
    }

    /**
     * @param array<int, array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}> $candidateIntervals
     * @return array<int, array{sessionId:?int,start:string,end:string,conflictingBookingId:int,conflictingSessionId:?int}>
     */
    private function conflictsForIntervals(
        Worker $worker,
        array $candidateIntervals,
        int $excludeBookingId,
        ?int $excludeSessionId,
    ): array {
        $conflicts = [];

        foreach ($candidateIntervals as $candidate) {
            foreach ($this->busyIntervalsFor($worker) as $busy) {
                if ($excludeSessionId !== null && $busy['sessionId'] === $excludeSessionId) {
                    continue;
                }

                // Preserve the existing one-booking exclusion behavior for normal
                // booking edits. Session acceptance must exclude only the current
                // session so another session of the same parent can still conflict.
                if ($excludeSessionId === null && $excludeBookingId > 0 && $busy['bookingId'] === $excludeBookingId) {
                    continue;
                }

                if ($candidate['start']->lt($busy['end']) && $candidate['end']->gt($busy['start'])) {
                    $conflicts[] = [
                        'sessionId' => $candidate['sessionId'],
                        'start' => $candidate['start']->toIso8601String(),
                        'end' => $candidate['end']->toIso8601String(),
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
     * @return array<int, array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}>
     */
    private function busyIntervalsFor(Worker $worker): array
    {
        $workerId = (int) $worker->id;

        if (array_key_exists($workerId, $this->busyIntervalsByWorker)) {
            return $this->busyIntervalsByWorker[$workerId];
        }

        $sessionBookingIds = [];
        if (Schema::hasTable('cleaning_booking_sessions')) {
            $sessionBookingIds = CleaningBookingSession::query()
                ->distinct()
                ->pluck('cleaning_booking_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $bookings = CleaningBooking::query()
            ->whereNotIn('status', [
                CleaningBookingStatus::Completed->value,
                CleaningBookingStatus::Cancelled->value,
            ])
            ->when(
                $sessionBookingIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('id', $sessionBookingIds),
            )
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
            $interval = $this->intervalForBookingParent($booking);
            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        if (Schema::hasTable('cleaning_booking_session_worker_assignments')) {
            $sessionAssignments = CleaningBookingSessionWorkerAssignment::query()
                ->with('session:id,cleaning_booking_id,scheduled_date,scheduled_time,duration_hours,status')
                ->where('worker_id', $workerId)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->get();

            foreach ($sessionAssignments as $assignment) {
                $session = $assignment->session;
                if (! $session instanceof CleaningBookingSession) {
                    continue;
                }

                if (in_array(
                    (string) ($session->status?->value ?? $session->status),
                    CleaningBookingSessionStatus::terminalValues(),
                    true,
                )) {
                    continue;
                }

                $interval = $this->intervalForSession($session);
                if ($interval !== null) {
                    $intervals[] = $interval;
                }
            }
        }

        return $this->busyIntervalsByWorker[$workerId] = $intervals;
    }

    /**
     * @return array<int, array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}>
     */
    private function intervalsForBooking(CleaningBooking $booking): array
    {
        if ((int) ($booking->id ?? 0) > 0 && Schema::hasTable('cleaning_booking_sessions')) {
            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', (int) $booking->id)
                ->whereNotIn('status', CleaningBookingSessionStatus::terminalValues())
                ->orderBy('sequence')
                ->get();

            if ($sessions->isNotEmpty()) {
                return $sessions
                    ->map(fn (CleaningBookingSession $session): ?array => $this->intervalForSession($session))
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        $interval = $this->intervalForBookingParent($booking);

        return $interval !== null ? [$interval] : [];
    }

    /** @return array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}|null */
    private function intervalForSession(CleaningBookingSession $session): ?array
    {
        $date = $session->scheduled_date instanceof CarbonInterface
            ? $session->scheduled_date->toDateString()
            : trim((string) $session->scheduled_date);

        return $this->intervalFromParts(
            $date,
            (string) $session->scheduled_time,
            (float) $session->duration_hours,
            (int) $session->cleaning_booking_id,
            (int) $session->id,
        );
    }

    /** @return array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}|null */
    private function intervalForBookingParent(CleaningBooking $booking): ?array
    {
        $date = $booking->scheduled_date instanceof CarbonInterface
            ? $booking->scheduled_date->toDateString()
            : trim((string) $booking->scheduled_date);
        $durationHours = (float) ($booking->total_hours ?? 0);

        if ($durationHours <= 0) {
            $durationHours = (float) ($booking->estimated_hours ?? 0);
        }

        return $this->intervalFromParts(
            $date,
            (string) $booking->scheduled_time,
            $durationHours,
            (int) ($booking->id ?? 0),
            null,
        );
    }

    /** @return array{bookingId:int,sessionId:?int,start:CarbonImmutable,end:CarbonImmutable}|null */
    private function intervalFromParts(
        string $date,
        string $time,
        float $durationHours,
        int $bookingId,
        ?int $sessionId,
    ): ?array {
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
            'start' => $start,
            'end' => $start->addMinutes($durationMinutes),
        ];
    }
}

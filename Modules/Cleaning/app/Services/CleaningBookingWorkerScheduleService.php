<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingWorkerScheduleService
{
    public function __construct(
        private readonly CleaningBookingSchedulePresenter $presenter,
        private readonly CleaningBookingWorkerSessionVisibilityService $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function present(
        CleaningBooking $booking,
        Worker $worker,
        ?CleaningBookingSession $includeHistoricalSession = null,
    ): array {
        $schedule = $this->presenter->present($booking, $worker);
        $visibleSessions = $this->visibility->visibleSessions($booking, $worker);
        $visibleSessions = $this->includeHistoricalMutationSession(
            $booking,
            $worker,
            $visibleSessions,
            $includeHistoricalSession,
        );

        return $this->sanitize(
            $this->scopeToWorker($schedule, $visibleSessions),
            $worker,
        );
    }

    /**
     * Mutation responses must contain the session that was just changed so the
     * worker client can reconcile the authoritative post-mutation state even
     * when normal visibility rules immediately remove a released assignment.
     * Subsequent schedule refetches continue to use the regular scoped view.
     *
     * @param  Collection<int, CleaningBookingSession>  $visibleSessions
     * @return Collection<int, CleaningBookingSession>
     */
    private function includeHistoricalMutationSession(
        CleaningBooking $booking,
        Worker $worker,
        Collection $visibleSessions,
        ?CleaningBookingSession $session,
    ): Collection {
        if (! $session instanceof CleaningBookingSession
            || (int) $session->cleaning_booking_id !== (int) $booking->id
            || $visibleSessions->contains(
                static fn (CleaningBookingSession $visible): bool => (int) $visible->id === (int) $session->id,
            )) {
            return $visibleSessions;
        }

        $historicalSession = CleaningBookingSession::query()
            ->whereKey($session->id)
            ->where('cleaning_booking_id', $booking->id)
            ->whereHas(
                'workerAssignments',
                static fn ($query) => $query->where('worker_id', $worker->id),
            )
            ->first();

        if (! $historicalSession instanceof CleaningBookingSession) {
            return $visibleSessions;
        }

        return $visibleSessions
            ->push($historicalSession)
            ->unique(static fn (CleaningBookingSession $item): int => (int) $item->id)
            ->sortBy(static fn (CleaningBookingSession $item): int => (int) $item->sequence)
            ->values();
    }

    /** @return array{wait: array<int>, replace: array<int>, cancel: array<int>} */
    private static function emptyActionWorkerIds(): array
    {
        return [
            CleaningBookingSessionAttendanceService::ACTION_WAIT => [],
            CleaningBookingSessionAttendanceService::ACTION_REPLACE => [],
            CleaningBookingSessionAttendanceService::ACTION_CANCEL => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @param  Collection<int, CleaningBookingSession>  $visibleSessions
     * @return array<string, mixed>
     */
    private function scopeToWorker(array $schedule, Collection $visibleSessions): array
    {
        $sessions = $schedule['sessions'] ?? [];
        $sessions = is_array($sessions) ? array_values($sessions) : [];
        $bookingSessionsCount = max(
            count($sessions),
            (int) ($schedule['sessionsCount'] ?? $schedule['daysCount'] ?? 0),
        );

        // Legacy one-day bookings have no persisted session id. They are already
        // authorized through the legacy booking relationship and must retain the
        // backwards-compatible fallback payload.
        $hasPersistedSessionPayload = collect($sessions)->contains(
            static fn (mixed $item): bool => is_array($item)
                && (int) ($item['sessionId'] ?? $item['id'] ?? 0) > 0,
        );

        if ($hasPersistedSessionPayload) {
            $visibleIds = $visibleSessions
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->flip();

            $sessions = array_values(array_filter(
                $sessions,
                static function (mixed $item) use ($visibleIds): bool {
                    if (! is_array($item)) {
                        return false;
                    }

                    $sessionId = (int) ($item['sessionId'] ?? $item['id'] ?? 0);

                    return $sessionId > 0 && $visibleIds->has($sessionId);
                },
            ));
        }

        $completed = $this->countStatus($sessions, CleaningBookingSessionStatus::Completed->value);
        $cancelled = $this->countStatus($sessions, CleaningBookingSessionStatus::Cancelled->value);
        $skipped = $this->countStatus($sessions, CleaningBookingSessionStatus::Skipped->value);
        $remaining = max(0, count($sessions) - $completed - $cancelled - $skipped);

        $schedule['bookingSessionsCount'] = $bookingSessionsCount;
        $schedule['bookingDaysCount'] = $bookingSessionsCount;
        $schedule['sessionsCount'] = count($sessions);
        $schedule['daysCount'] = count($sessions);
        $schedule['mySessionsCount'] = count($sessions);
        $schedule['completedSessionsCount'] = $completed;
        $schedule['completedDaysCount'] = $completed;
        $schedule['myCompletedSessionsCount'] = $completed;
        $schedule['cancelledSessionsCount'] = $cancelled;
        $schedule['cancelledDaysCount'] = $cancelled;
        $schedule['skippedSessionsCount'] = $skipped;
        $schedule['remainingSessionsCount'] = $remaining;
        $schedule['remainingDaysCount'] = $remaining;
        $schedule['myRemainingSessionsCount'] = $remaining;
        $schedule['sessions'] = $sessions;
        $schedule['totalHours'] = round($this->visibleTotalHours($sessions), 2);
        $schedule['firstDate'] = $this->boundaryDate($sessions, first: true);
        $schedule['lastDate'] = $this->boundaryDate($sessions, first: false);
        $schedule['nextSession'] = $this->nextVisibleSession($sessions);

        // Preserve the parent booking's multi-day identity for older clients,
        // while the worker-specific counts above describe only this worker's
        // returned execution scope.
        if ($bookingSessionsCount > 1) {
            $schedule['mode'] = 'multi_day';
            $schedule['isMultiSession'] = true;
            $schedule['isMultiDay'] = true;
        }

        return $schedule;
    }

    /** @param array<int, mixed> $sessions */
    private function countStatus(array $sessions, string $status): int
    {
        return count(array_filter(
            $sessions,
            static fn (mixed $item): bool => is_array($item)
                && (string) ($item['status'] ?? '') === $status,
        ));
    }

    /** @param array<int, mixed> $sessions */
    private function visibleTotalHours(array $sessions): float
    {
        return (float) collect($sessions)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->reject(static fn (array $item): bool => in_array(
                (string) ($item['status'] ?? ''),
                [
                    CleaningBookingSessionStatus::Cancelled->value,
                    CleaningBookingSessionStatus::Skipped->value,
                    CleaningBookingSessionStatus::Superseded->value,
                ],
                true,
            ))
            ->sum(static fn (array $item): float => (float) ($item['durationHours'] ?? $item['hours'] ?? 0));
    }

    /** @param array<int, mixed> $sessions */
    private function boundaryDate(array $sessions, bool $first): ?string
    {
        $dates = collect($sessions)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): ?string => filled($item['scheduledDate'] ?? $item['date'] ?? null)
                ? (string) ($item['scheduledDate'] ?? $item['date'])
                : null)
            ->filter()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        return $first ? (string) $dates->first() : (string) $dates->last();
    }

    /**
     * @param  array<int, mixed>  $sessions
     * @return array<string, mixed>|null
     */
    private function nextVisibleSession(array $sessions): ?array
    {
        $next = collect($sessions)
            ->filter(static function (mixed $item): bool {
                if (! is_array($item)) {
                    return false;
                }

                $status = (string) ($item['status'] ?? '');

                return ! in_array($status, [
                    CleaningBookingSessionStatus::Completed->value,
                    CleaningBookingSessionStatus::Cancelled->value,
                    CleaningBookingSessionStatus::Skipped->value,
                    CleaningBookingSessionStatus::Superseded->value,
                    CleaningBookingSessionStatus::Paused->value,
                ], true);
            })
            ->sortBy(static function (array $item): string {
                $startsAt = (string) ($item['startsAt'] ?? '');
                if ($startsAt !== '') {
                    return $startsAt;
                }

                return sprintf(
                    '%sT%s-%08d',
                    (string) ($item['scheduledDate'] ?? $item['date'] ?? '9999-12-31'),
                    (string) ($item['scheduledTime'] ?? $item['time'] ?? '23:59:59'),
                    (int) ($item['sequence'] ?? PHP_INT_MAX),
                );
            })
            ->first();

        return is_array($next) ? $next : null;
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private function sanitize(array $schedule, Worker $worker): array
    {
        $decorate = function (mixed $value) use ($worker): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $incidents = data_get($value, 'attendance.incidents', []);
            $incidents = is_array($incidents) ? $incidents : [];
            $ownIncidents = array_values(array_filter(
                $incidents,
                static fn (mixed $item): bool => is_array($item)
                    && (int) ($item['workerId'] ?? 0) === (int) $worker->id,
            ));
            $incident = $ownIncidents[0] ?? null;

            $ownAssignment = $value['myAssignment']
                ?? $value['myWorkerAssignment']
                ?? $value['workerAssignmentState']
                ?? null;
            $value['workerAssignments'] = is_array($ownAssignment)
                ? [$ownAssignment]
                : [];

            $value['canReportLate'] = false;
            $value['canReportNoTravel'] = false;
            $value['lateWorkerIds'] = [];
            $value['noTravelWorkerIds'] = [];
            $value['reportableLateWorkerIds'] = [];
            $value['reportableNoTravelWorkerIds'] = [];
            $value['allowedAttendanceActions'] = [];
            $value['attendanceActionWorkerIds'] = self::emptyActionWorkerIds();

            $attendance = $value['attendance'] ?? [];
            $attendance = is_array($attendance) ? $attendance : [];
            $attendance['allowedActions'] = [];
            $attendance['actionWorkerIds'] = self::emptyActionWorkerIds();
            $attendance['incidents'] = $ownIncidents;
            $value['attendance'] = $attendance;

            if (! is_array($incident)) {
                $value['workerAttendanceNotice'] = null;

                return $value;
            }

            $resolvedAt = $incident['resolvedAt'] ?? null;
            $isResolved = filled($resolvedAt);
            $isNoTravel = filled($incident['noTravelReportedAt'] ?? null);
            $message = $isResolved
                ? 'تمت معالجة بلاغ الحضور.'
                : ($isNoTravel
                    ? 'أبلغ العميل عن عدم بدء التوجه. ابدأ التوجه الآن لتحديث الحالة.'
                    : 'أبلغ العميل عن تأخر بدء التوجه. ابدأ التوجه الآن لتحديث الحالة.');

            $value['workerAttendanceNotice'] = [
                'type' => $isNoTravel ? 'no_travel' : 'late',
                'message' => $message,
                'action' => $incident['action'] ?? null,
                'note' => $incident['note'] ?? null,
                'resolvedAt' => $resolvedAt,
                'isResolved' => $isResolved,
            ];

            if (! $isResolved) {
                $value['statusLabel'] = $message;
            }

            return $value;
        };

        $sessions = $schedule['sessions'] ?? [];
        if (is_array($sessions)) {
            $schedule['sessions'] = array_map($decorate, $sessions);
        }

        if (array_key_exists('nextSession', $schedule) && $schedule['nextSession'] !== null) {
            $schedule['nextSession'] = $decorate($schedule['nextSession']);
        }

        return $schedule;
    }
}

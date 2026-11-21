<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class WorkerComplianceOfficialBookingExclusion
{
    /**
     * Return true when official work explains the full observation window. A null return means
     * relevant schedule or site evidence is incomplete and the candidate must be suppressed.
     *
     * @param  array{started_at: CarbonImmutable, ended_at: CarbonImmutable}  $run
     */
    public function explainsVisit(
        int $workerId,
        float $siteLatitude,
        float $siteLongitude,
        array $run,
        bool $lockForUpdate = false,
    ): ?bool {
        $start = $run['started_at'];
        $end = $run['ended_at'];
        $relevantStart = $start->subDay();
        $relevantEnd = $end->addSeconds(1);
        $scheduleStartDate = $start->subDays(2)->toDateString();
        $scheduleEndDate = $end->addDays(2)->toDateString();

        $bookingAssignmentQuery = CleaningBookingWorkerAssignment::query()
            ->where('worker_id', $workerId)
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $relevantEnd)
            ->where(function ($query) use ($relevantStart, $relevantEnd, $scheduleStartDate, $scheduleEndDate): void {
                $query->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->orWhereBetween('updated_at', [$relevantStart, $relevantEnd])
                    ->orWhere(function ($timestamps) use ($relevantStart, $relevantEnd): void {
                        $timestamps->where('started_travel_at', '<=', $relevantEnd)
                            ->where(function ($work) use ($relevantStart): void {
                                $work->whereNull('work_finished_at')->orWhere('work_finished_at', '>=', $relevantStart);
                            });
                    })
                    ->orWhereHas('booking', function ($booking) use ($scheduleStartDate, $scheduleEndDate, $relevantStart, $relevantEnd): void {
                        $booking->whereBetween('scheduled_date', [$scheduleStartDate, $scheduleEndDate])
                            ->orWhereBetween('cancelled_at', [$relevantStart, $relevantEnd])
                            ->orWhere(function ($work) use ($relevantStart, $relevantEnd): void {
                                $work->whereNotNull('work_started_at')
                                    ->where('work_started_at', '<=', $relevantEnd)
                                    ->where(function ($finish) use ($relevantStart): void {
                                        $finish->whereNull('work_finished_at')->orWhere('work_finished_at', '>=', $relevantStart);
                                    });
                            });
                    });
            })
            ->with('booking.sessions')
            ->limit(501);
        if ($lockForUpdate) {
            $bookingAssignmentQuery->lockForUpdate();
        }
        $bookingAssignments = $bookingAssignmentQuery->get();
        if ($bookingAssignments->count() > 500) {
            return null;
        }

        foreach ($bookingAssignments as $assignment) {
            $booking = $assignment->booking;
            if ($lockForUpdate && $booking !== null) {
                $booking = CleaningBooking::query()->whereKey($booking->getKey())->lockForUpdate()->first();
            }
            if (! $booking instanceof CleaningBooking || $booking->sessions->isNotEmpty()) {
                continue;
            }

            $interval = $this->capAtAssignmentLifecycleEnd(
                $this->bookingInterval($booking, $assignment->started_travel_at, $assignment->work_finished_at),
                null,
                (string) ($assignment->status?->value ?? $assignment->status),
                $booking->cancelled_at,
                $assignment->updated_at,
            );
            $result = $this->checkSiteAndInterval($booking, $interval, $start, $end, $siteLatitude, $siteLongitude);
            if ($result !== false) {
                return $result;
            }
        }

        $sessionAssignmentQuery = CleaningBookingSessionWorkerAssignment::query()
            ->where('worker_id', $workerId)
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $relevantEnd)
            ->where(function ($query) use ($relevantStart, $relevantEnd, $scheduleStartDate, $scheduleEndDate): void {
                $query->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->orWhereBetween('released_at', [$relevantStart, $relevantEnd])
                    ->orWhereBetween('updated_at', [$relevantStart, $relevantEnd])
                    ->orWhereHas('session', function ($session) use ($scheduleStartDate, $scheduleEndDate, $relevantStart, $relevantEnd): void {
                        $session->whereBetween('scheduled_date', [$scheduleStartDate, $scheduleEndDate])
                            ->orWhereBetween('cancelled_at', [$relevantStart, $relevantEnd])
                            ->orWhere(function ($work) use ($relevantStart, $relevantEnd): void {
                                $work->whereNotNull('work_started_at')
                                    ->where('work_started_at', '<=', $relevantEnd)
                                    ->where(function ($finish) use ($relevantStart): void {
                                        $finish->whereNull('work_finished_at')->orWhere('work_finished_at', '>=', $relevantStart);
                                    });
                            });
                    });
            })
            ->with('session.booking')
            ->limit(501);
        if ($lockForUpdate) {
            $sessionAssignmentQuery->lockForUpdate();
        }
        $sessionAssignments = $sessionAssignmentQuery->get();
        if ($sessionAssignments->count() > 500) {
            return null;
        }

        foreach ($sessionAssignments as $assignment) {
            $session = $assignment->session;
            if ($lockForUpdate && $session !== null) {
                $session = $session->newQuery()->whereKey($session->getKey())->lockForUpdate()->first();
            }
            $booking = $session?->booking;
            if ($lockForUpdate && $booking !== null) {
                $booking = CleaningBooking::query()->whereKey($booking->getKey())->lockForUpdate()->first();
            }
            if (! $session || ! $booking instanceof CleaningBooking) {
                continue;
            }

            $sessionStatus = (string) ($session->status?->value ?? $session->status);
            $intervalStart = $assignment->started_travel_at ?? $session->startsAt();
            if ($intervalStart !== null) {
                $intervalStart = CarbonImmutable::parse($intervalStart);
            }
            $intervalEnd = $assignment->work_finished_at
                ?? $session->endsAt()
                ?? ($sessionStatus === CleaningBookingSessionStatus::Completed->value ? null : CarbonImmutable::now());
            if ($intervalEnd !== null) {
                $intervalEnd = CarbonImmutable::parse($intervalEnd);
            }
            if ($assignment->work_finished_at === null
                && in_array($sessionStatus, CleaningBookingSessionStatus::activeValues(), true)) {
                $activeUntil = CarbonImmutable::now();
                if ($session->open_time_ceiling_ends_at !== null) {
                    $ceiling = CarbonImmutable::parse($session->open_time_ceiling_ends_at);
                    if ($ceiling->greaterThan($activeUntil)) {
                        $activeUntil = $ceiling;
                    }
                }
                if ($intervalEnd === null || $intervalEnd->lessThan($activeUntil)) {
                    $intervalEnd = $activeUntil;
                }
            }
            if ($intervalEnd === null && $sessionStatus !== CleaningBookingSessionStatus::Completed->value) {
                $intervalEnd = CarbonImmutable::now();
            }
            $result = $this->checkSiteAndInterval(
                $booking,
                $this->capAtAssignmentLifecycleEnd(
                    [$intervalStart, $intervalEnd],
                    $assignment->released_at,
                    $sessionStatus,
                    $session->cancelled_at,
                    $session->updated_at,
                ),
                $start,
                $end,
                $siteLatitude,
                $siteLongitude,
            );
            if ($result !== false) {
                return $result;
            }
        }

        $legacyBookingQuery = CleaningBooking::query()
            ->where('worker_id', $workerId)
            ->whereIn('status', [
                CleaningBookingStatus::WorkerAssigned->value,
                CleaningBookingStatus::AwaitingStartVerification->value,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
                CleaningBookingStatus::InProgress->value,
                CleaningBookingStatus::AwaitingCustomerCompletion->value,
                CleaningBookingStatus::TimeExtensionRequested->value,
                CleaningBookingStatus::Completed->value,
                CleaningBookingStatus::Cancelled->value,
            ])
            ->where(function ($query) use ($start, $end, $scheduleStartDate, $scheduleEndDate): void {
                $query->whereIn('status', [
                    CleaningBookingStatus::WorkerAssigned->value,
                    CleaningBookingStatus::AwaitingStartVerification->value,
                    CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
                    CleaningBookingStatus::InProgress->value,
                    CleaningBookingStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingStatus::TimeExtensionRequested->value,
                ])
                    ->orWhereBetween('updated_at', [$start->subDay(), $end->addSeconds(1)])
                    ->orWhereBetween('scheduled_date', [$scheduleStartDate, $scheduleEndDate])
                    ->orWhereBetween('work_started_at', [$start->subDay(), $end->addSeconds(1)])
                    ->orWhereBetween('work_finished_at', [$start->subDay(), $end->addSeconds(1)])
                    ->orWhereBetween('cancelled_at', [$start->subDay(), $end->addSeconds(1)]);
            })
            ->whereDoesntHave('workerAssignments')
            ->whereDoesntHave('sessions')
            ->limit(501);
        if ($lockForUpdate) {
            $legacyBookingQuery->lockForUpdate();
        }
        $legacyBookings = $legacyBookingQuery->get();
        if ($legacyBookings->count() > 500) {
            return null;
        }

        foreach ($legacyBookings as $booking) {
            if ((int) $booking->number_of_workers !== 1) {
                return null;
            }

            $interval = $this->capAtAssignmentLifecycleEnd(
                $this->bookingInterval($booking, $booking->started_travel_at, $booking->work_finished_at),
                null,
                (string) ($booking->status?->value ?? $booking->status),
                $booking->cancelled_at,
                $booking->updated_at,
            );
            $result = $this->checkSiteAndInterval($booking, $interval, $start, $end, $siteLatitude, $siteLongitude);
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function bookingInterval(CleaningBooking $booking, mixed $travelStartedAt, mixed $workFinishedAt): array
    {
        try {
            $scheduledStart = CarbonImmutable::parse(
                $booking->scheduled_date->format('Y-m-d').' '.$booking->scheduled_time,
                (string) config('cleaning_booking_v2.timezone'),
            );
        } catch (Throwable) {
            if ($travelStartedAt !== null && $workFinishedAt !== null) {
                return [CarbonImmutable::parse($travelStartedAt), CarbonImmutable::parse($workFinishedAt)];
            }

            return [null, null];
        }

        $start = $travelStartedAt !== null ? CarbonImmutable::parse($travelStartedAt) : $scheduledStart;
        $scheduledMinutes = max(1, (int) ceil((float) $booking->estimated_hours * 60 / max(1, (int) $booking->number_of_workers)));
        $end = $workFinishedAt !== null
            ? CarbonImmutable::parse($workFinishedAt)
            : ($booking->open_time_ceiling_ends_at !== null
                ? CarbonImmutable::parse($booking->open_time_ceiling_ends_at)
                : $scheduledStart->addMinutes($scheduledMinutes));

        if ($workFinishedAt === null && in_array(
            (string) ($booking->status?->value ?? $booking->status),
            [
                CleaningBookingStatus::WorkerAssigned->value,
                CleaningBookingStatus::AwaitingStartVerification->value,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
                CleaningBookingStatus::InProgress->value,
                CleaningBookingStatus::AwaitingCustomerCompletion->value,
                CleaningBookingStatus::TimeExtensionRequested->value,
            ],
            true,
        )) {
            $now = CarbonImmutable::now();
            if ($end->lessThan($now)) {
                $end = $now;
            }
        }

        if ($booking->cancelled_at !== null || (string) ($booking->status?->value ?? $booking->status) === CleaningBookingStatus::Cancelled->value) {
            $cancelledAt = $booking->cancelled_at ?? $booking->updated_at;
            $cancelledAt = CarbonImmutable::parse($cancelledAt);
            if ($cancelledAt->lessThan($end)) {
                $end = $cancelledAt;
            }
        }

        return [$start, $end];
    }

    /**
     * @param  array{0: ?CarbonImmutable, 1: ?CarbonImmutable}  $interval
     */
    private function checkSiteAndInterval(
        CleaningBooking $booking,
        array $interval,
        CarbonImmutable $runStart,
        CarbonImmutable $runEnd,
        float $siteLatitude,
        float $siteLongitude,
    ): ?bool {
        [$start, $end] = $interval;
        if ($start === null || $end === null) {
            return null;
        }

        if ($end->lessThan($runStart) || $start->greaterThan($runEnd)) {
            return false;
        }

        if ($booking->address_latitude === null || $booking->address_longitude === null) {
            return null;
        }

        $distance = WorkerComplianceVisitEvaluator::distanceMeters(
            $siteLatitude,
            $siteLongitude,
            (float) $booking->address_latitude,
            (float) $booking->address_longitude,
        );

        return $distance <= (float) config('cleaning.worker_compliance.proximity_radius_m', 100);
    }

    /**
     * @param  array{0: ?CarbonImmutable, 1: ?CarbonImmutable}  $interval
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function capAtAssignmentLifecycleEnd(
        array $interval,
        mixed $releasedAt,
        string $currentStatus,
        mixed $cancelledAt,
        mixed $updatedAt,
    ): array {
        [$start, $end] = $interval;
        if ($end === null) {
            return $interval;
        }

        $releaseTime = $releasedAt;
        if ($releaseTime === null && in_array($currentStatus, [
            CleaningBookingWorkerAssignmentStatus::Rejected->value,
            CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
            CleaningBookingWorkerAssignmentStatus::Cancelled->value,
            CleaningBookingSessionStatus::Cancelled->value,
            CleaningBookingSessionStatus::Skipped->value,
            CleaningBookingSessionStatus::Superseded->value,
            CleaningBookingStatus::Cancelled->value,
        ], true)) {
            $releaseTime = $updatedAt;
        }
        if ($cancelledAt !== null) {
            $cancelledAt = CarbonImmutable::parse($cancelledAt);
            if ($releaseTime === null || CarbonImmutable::parse($releaseTime)->greaterThan($cancelledAt)) {
                $releaseTime = $cancelledAt;
            }
        }
        if ($releaseTime !== null) {
            $released = CarbonImmutable::parse($releaseTime);
            if ($released->lessThan($end)) {
                $end = $released;
            }
        }

        return [$start, $end];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DateTimeImmutable;
use DomainException;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Immutable, signed appointment membership for new independent daily bookings. */
final class CleaningV3DailyQuoteScope
{
    public function fromPlan(array $plan): array
    {
        $appointments = [];
        $dates = [];
        foreach ($plan['appointments'] as $appointment) {
            if (count($appointment['visits']) !== 1) {
                throw new DomainException('V3_DAILY_APPOINTMENT_REVIEW_REQUIRED');
            }
            $visit = $appointment['visits'][0];
            $date = $visit['scheduledDate'];
            if (isset($dates[$date])) {
                // Rescheduling two occurrences onto one date needs an explicit
                // commercial grouping decision; never silently charge twice.
                throw new DomainException('V3_DAILY_APPOINTMENT_REVIEW_REQUIRED');
            }
            $dates[$date] = true;
            $appointments[] = [
                'appointmentKey' => $appointment['appointmentKey'],
                'visits' => [array_intersect_key($visit, array_flip([
                    'occurrenceKey', 'scheduledDate', 'scheduledTime', 'durationMinutes', 'requiredWorkers',
                ]))],
            ];
        }

        return $appointments;
    }

    /** Validate the full signed plan, not a mutable current sibling roster. */
    public function validate(array $snapshot): void
    {
        $appointments = $snapshot['dailyAppointments'] ?? null;
        if (! is_array($appointments) || ! array_is_list($appointments) || $appointments === []) {
            throw new DomainException('V3_INVALID_DAILY_QUOTE_SCOPE');
        }
        // Verification of an accepted historical quote must not depend on
        // today's date. The admission pipeline separately enforces future dates.
        $plan = app(CleaningV3VisitPlanner::class)->plan([
            'contractVersion' => 3,
            'catalogVersionKey' => $snapshot['catalogVersionKey'],
            'catalogRevision' => $snapshot['catalogRevision'],
            'appointments' => $appointments,
        ], new DateTimeImmutable('1970-01-01T00:00:00Z'));
        $canonical = $this->fromPlan($plan);
        if ($canonical != $appointments
            || ! hash_equals($snapshot['draftFingerprint'], $plan['draftFingerprint'])
            || count($canonical) !== count($snapshot['visits'])) {
            throw new DomainException('V3_INVALID_DAILY_QUOTE_SCOPE');
        }
        foreach ($canonical as $index => $appointment) {
            $visit = $appointment['visits'][0];
            $identity = $snapshot['visits'][$index];
            if ($identity['occurrenceKey'] !== $visit['occurrenceKey']
                || $identity['appointmentKey'] !== $appointment['appointmentKey']
                || $identity['pricedWorkers'] !== $visit['requiredWorkers']
                || ($snapshot['settlementBasis']['visits'][$visit['occurrenceKey']]['plannedMinutes'] ?? null) !== $visit['durationMinutes']) {
                throw new DomainException('V3_INVALID_DAILY_QUOTE_SCOPE');
            }
        }
    }

    public function appointment(array $snapshot, int $sequence): array
    {
        $appointment = $snapshot['dailyAppointments'][$sequence - 1] ?? null;
        if ($sequence < 1 || ! is_array($appointment)) {
            throw new DomainException('V3_INVALID_DAILY_QUOTE_SCOPE');
        }

        return ['appointmentKey' => $appointment['appointmentKey'], ...$appointment['visits'][0]];
    }

    public function assertExecution(array $snapshot, int $sequence, CleaningBookingSession $visit, bool $checkOriginalSchedule = false): void
    {
        $expected = $this->appointment($snapshot, $sequence);
        if ($visit->occurrence_key !== $expected['occurrenceKey']
            || $visit->appointment_key !== $expected['appointmentKey']
            || $visit->requiredWorkerCount() !== $expected['requiredWorkers']
            || ($checkOriginalSchedule && (
                $visit->scheduled_date?->format('Y-m-d') !== $expected['scheduledDate']
                || mb_substr((string) $visit->scheduled_time, 0, 5) !== $expected['scheduledTime']
                || (int) round((float) $visit->duration_hours * 60) !== $expected['durationMinutes']
            ))) {
            throw new DomainException('V3_DAILY_QUOTE_EXECUTION_MISMATCH');
        }
    }
}

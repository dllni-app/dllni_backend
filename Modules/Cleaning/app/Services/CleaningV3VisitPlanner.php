<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Deterministic NON-FINANCIAL grouping of customer-proposed V3 visits.
 *
 * This does not reserve a worker, validate stock, quote prices, charge a
 * surcharge, create sessions or authorize any execution. Every result still
 * requires server-side geographic, worker and funding validation.
 *
 * Appointment keys group visits without creating a financial identity.
 * Occurrence keys stay stable across reschedules and are globally unique in
 * one booking. Each future visit receives its persisted session ID ONLY from
 * an approved transactional booking writer, never from this planner.
 */
final class CleaningV3VisitPlanner
{
    public function __construct(
        private readonly string $timezone = 'Asia/Damascus',
        private readonly int $maxActiveMinutes = 480,
        private readonly int $maxRequestedWorkers = 20,
    ) {
        if ($maxActiveMinutes < 1 || $maxActiveMinutes > 480
            || $maxRequestedWorkers < 1 || $maxRequestedWorkers > 50) {
            throw new InvalidArgumentException('Invalid draft planner safety limits.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function plan(array $input, ?DateTimeImmutable $now = null): array
    {
        if (($input['contractVersion'] ?? null) !== 3) {
            $this->invalid('contractVersion', 'V3_CONTRACT_REQUIRED');
        }
        $key = $this->key($input['catalogVersionKey'] ?? null, 'catalogVersionKey');
        $revision = $input['catalogRevision'] ?? null;
        if (! is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            $this->invalid('catalogRevision', 'INVALID_CATALOG_REVISION');
        }
        if ($this->containsFinancialFields($input)) {
            $this->invalid('pricing', 'CLIENT_FINANCIAL_INPUT_FORBIDDEN');
        }

        if (array_diff(array_keys($input), ['contractVersion', 'catalogVersionKey', 'catalogRevision', 'answers', 'appointments']) !== []) {
            $this->invalid('appointments', 'INVALID_V3_PLAN_FIELDS');
        }

        $appointments = $input['appointments'] ?? null;
        if (! is_array($appointments) || ! array_is_list($appointments)
            || count($appointments) < 1 || count($appointments) > 30) {
            $this->invalid('appointments', 'INVALID_APPOINTMENT_COUNT');
        }

        $tz = new DateTimeZone($this->timezone);
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $seenAppointments = [];
        $seenOccurrences = [];
        $normalized = [];
        $totalVisits = 0;
        $totalMinutes = 0;
        $peakWorkers = 0;

        foreach ($appointments as $groupIndex => $group) {
            if (! is_array($group) || array_is_list($group)) {
                $this->invalid("appointments.$groupIndex", 'INVALID_APPOINTMENT');
            }
            if ($this->containsFinancialFields($group)) {
                $this->invalid("appointments.$groupIndex.pricing", 'CLIENT_FINANCIAL_INPUT_FORBIDDEN');
            }
            if (array_diff(array_keys($group), ['appointmentKey', 'visits']) !== []) {
                $this->invalid("appointments.$groupIndex", 'INVALID_V3_PLAN_FIELDS');
            }
            $appointmentKey = $this->key($group['appointmentKey'] ?? null, "appointments.$groupIndex.appointmentKey");
            if (isset($seenAppointments[$appointmentKey])) {
                $this->invalid("appointments.$groupIndex.appointmentKey", 'DUPLICATE_APPOINTMENT_KEY');
            }
            $seenAppointments[$appointmentKey] = true;

            $visits = $group['visits'] ?? null;
            if (! is_array($visits) || ! array_is_list($visits)
                || count($visits) < 1 || count($visits) > 10) {
                $this->invalid("appointments.$groupIndex.visits", 'INVALID_VISIT_COUNT');
            }
            $planned = [];
            $appointmentDate = null;
            foreach ($visits as $visitIndex => $visit) {
                $path = "appointments.$groupIndex.visits.$visitIndex";
                if (! is_array($visit) || array_is_list($visit)) {
                    $this->invalid($path, 'INVALID_VISIT');
                }
                if ($this->containsFinancialFields($visit)) {
                    $this->invalid("$path.pricing", 'CLIENT_FINANCIAL_INPUT_FORBIDDEN');
                }
                if (array_diff(array_keys($visit), ['occurrenceKey', 'scheduledDate', 'scheduledTime', 'durationMinutes', 'requiredWorkers']) !== []) {
                    $this->invalid($path, 'INVALID_V3_PLAN_FIELDS');
                }
                $occurrenceKey = $this->key($visit['occurrenceKey'] ?? null, "$path.occurrenceKey");
                if (isset($seenOccurrences[$occurrenceKey])) {
                    $this->invalid("$path.occurrenceKey", 'DUPLICATE_OCCURRENCE_KEY');
                }
                $seenOccurrences[$occurrenceKey] = true;

                $date = $visit['scheduledDate'] ?? null;
                $time = $visit['scheduledTime'] ?? null;
                if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                    || ! is_string($time) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                    $this->invalid("$path.scheduledDate", 'INVALID_LOCAL_START');
                }
                $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", $tz);
                if ($start === false || $start->format('Y-m-d H:i') !== "$date $time"
                    || $start <= $now) {
                    $this->invalid("$path.scheduledDate", 'VISIT_MUST_BE_FUTURE');
                }
                if ($appointmentDate !== null && $date !== $appointmentDate) {
                    $this->invalid("$path.scheduledDate", 'APPOINTMENT_MUST_USE_ONE_LOCAL_DATE');
                }
                $appointmentDate ??= $date;

                $duration = $visit['durationMinutes'] ?? null;
                if (! is_int($duration) || $duration < 1 || $duration > $this->maxActiveMinutes) {
                    $this->invalid("$path.durationMinutes", 'INVALID_ACTIVE_DURATION');
                }
                $workers = $visit['requiredWorkers'] ?? null;
                if (! is_int($workers) || $workers < 1 || $workers > $this->maxRequestedWorkers) {
                    $this->invalid("$path.requiredWorkers", 'INVALID_CREW_CAPACITY');
                }

                $end = $start->modify("+$duration minutes");
                if ($end->format('Y-m-d') !== $date) {
                    $this->invalid("$path.durationMinutes", 'CROSS_MIDNIGHT_NOT_SUPPORTED');
                }

                $totalMinutes += $duration;
                $peakWorkers = max($peakWorkers, $workers);
                $planned[] = [
                    'occurrenceKey' => $occurrenceKey,
                    'scheduledDate' => $date,
                    'scheduledTime' => $time,
                    'startAt' => $start->format(DATE_ATOM),
                    'endAt' => $end->format(DATE_ATOM),
                    'durationMinutes' => $duration,
                    'requiredWorkers' => $workers,
                    // No fake visit ID: persisted only after signed quote acceptance.
                    'visitId' => null,
                    'requiresGeographicCheck' => true,
                    'requiresCrewConflictCheck' => true,
                ];
                $totalVisits++;
                if ($totalVisits > 90) {
                    $this->invalid('appointments', 'VISIT_LIMIT_EXCEEDED');
                }
            }

            usort($planned, static fn (array $a, array $b): int => [$a['startAt'], $a['occurrenceKey']] <=> [$b['startAt'], $b['occurrenceKey']]);

            $overlap = false;
            $latestEnd = null;
            foreach ($planned as $item) {
                if ($latestEnd !== null && strcmp($item['startAt'], $latestEnd) < 0) {
                    $overlap = true;
                }
                $latestEnd = $latestEnd === null || strcmp($item['endAt'], $latestEnd) > 0
                    ? $item['endAt'] : $latestEnd;
            }

            $normalized[] = [
                'appointmentKey' => $appointmentKey,
                'date' => $appointmentDate,
                'startAt' => $planned[0]['startAt'],
                'endAt' => $latestEnd,
                'sameDayOverlapRequiresCrewResolution' => $overlap,
                'visits' => $planned,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => [$a['startAt'], $a['appointmentKey']] <=> [$b['startAt'], $b['appointmentKey']]);

        $fingerprintPayload = [
            'contractVersion' => 3,
            'catalogVersionKey' => $key,
            'catalogRevision' => $revision,
            'timezone' => $tz->getName(),
            'appointments' => $normalized,
        ];

        return [
            ...$fingerprintPayload,
            'draftFingerprint' => hash('sha256', json_encode(
                $fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )),
            'summary' => [
                'appointmentCount' => count($normalized),
                'visitCount' => $totalVisits,
                'totalActiveMinutes' => $totalMinutes,
                'peakRequestedWorkers' => $peakWorkers,
                'currency' => 'SYP',
                'price' => null,
                'pricingApproved' => false,
                'surchargeApproved' => false,
            ],
            'canQuote' => false,
            'canCreateBooking' => false,
            'requiresGeographicCheck' => true,
            'requiresWorkerAvailabilityCheck' => true,
            'requiresClearedFundingAdmission' => true,
            'previewOnly' => true,
        ];
    }

    private function key(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D', $value) !== 1) {
            $this->invalid($field, 'INVALID_STABLE_KEY');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function containsFinancialFields(array $value): bool
    {
        return array_intersect(
            array_keys($value),
            ['quoteId', 'quoteSignature', 'price', 'pricing', 'amount',
                'totalPrice', 'surcharge', 'travelFee', 'payment', 'currency',
                'workerPayout', 'fundingSourceId', 'idempotencyKey'],
        ) !== [];
    }

    private function invalid(string $field, string $code): never
    {
        throw new CleaningV3VisitPlanException($field, $code);
    }
}

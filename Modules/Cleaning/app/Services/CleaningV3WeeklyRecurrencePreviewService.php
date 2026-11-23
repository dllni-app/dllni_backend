<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Non-financial V3 weekly recurrence preview.
 *
 * The server generates initial stable occurrence identities; once persisted,
 * rescheduling or skipping an occurrence must preserve that identity and its
 * financial revision, not call this generator over existing bookings.
 * Independent visit funding, accepted workers and notifications are separate.
 */
final class CleaningV3WeeklyRecurrencePreviewService
{
    public function __construct(
        private readonly CleaningV3VisitPlanner $visits,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preview(array $input, ?DateTimeImmutable $now = null): array
    {
        $allowed = [
            'seriesKey', 'catalogVersionKey', 'catalogRevision',
            'startDate', 'startTime', 'weekdays', 'intervalWeeks',
            'occurrenceCount', 'durationMinutes', 'requiredWorkers',
        ];
        if (array_is_list($input)
            || array_diff(array_keys($input), $allowed) !== []
            || ! $this->key($input['seriesKey'] ?? null)
            || ! $this->key($input['catalogVersionKey'] ?? null)
            || ! is_string($input['catalogRevision'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $input['catalogRevision']) !== 1
            || ! is_string($input['startDate'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input['startDate']) !== 1
            || ! is_string($input['startTime'] ?? null)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $input['startTime']) !== 1
            || ! is_int($input['intervalWeeks'] ?? null)
            || $input['intervalWeeks'] < 1 || $input['intervalWeeks'] > 12
            || ! is_int($input['occurrenceCount'] ?? null)
            || $input['occurrenceCount'] < 1 || $input['occurrenceCount'] > 30
            || ! is_int($input['durationMinutes'] ?? null)
            || $input['durationMinutes'] < 30 || $input['durationMinutes'] > 480
            || ! is_int($input['requiredWorkers'] ?? null)
            || $input['requiredWorkers'] < 1 || $input['requiredWorkers'] > 20
            || ! is_array($input['weekdays'] ?? null)
            || ! array_is_list($input['weekdays'])
            || $input['weekdays'] === []
            || count($input['weekdays']) > 7) {
            throw new DomainException('INVALID_V3_RECURRENCE_RULE');
        }

        $weekdays = [];
        foreach ($input['weekdays'] as $weekday) {
            if (! is_int($weekday) || $weekday < 1 || $weekday > 7
                || isset($weekdays[$weekday])) {
                throw new DomainException('INVALID_V3_RECURRENCE_WEEKDAY');
            }
            $weekdays[$weekday] = true;
        }
        $days = array_keys($weekdays);
        sort($days, SORT_NUMERIC);

        $tz = new DateTimeZone('Asia/Damascus');
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $anchor = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i', $input['startDate'].' '.$input['startTime'], $tz,
        );
        if ($anchor === false
            || $anchor->format('Y-m-d H:i') !== $input['startDate'].' '.$input['startTime']
            || $anchor <= $now) {
            throw new DomainException('V3_RECURRENCE_START_MUST_BE_FUTURE');
        }
        $maximumEnd = $now->modify('+365 days');
        if ($anchor > $maximumEnd) {
            throw new DomainException('V3_RECURRENCE_HORIZON_EXCEEDED');
        }

        $monday = $anchor->modify('monday this week')->setTime(0, 0);
        $appointments = [];
        $sequence = 1;

        for ($week = 0; $week <= 53 && $sequence <= $input['occurrenceCount'];
            $week += $input['intervalWeeks']) {
            $weekStart = $monday->modify('+'.$week.' weeks');
            foreach ($days as $weekday) {
                $start = $weekStart->modify('+'.($weekday - 1).' days');
                [$hour, $minute] = array_map('intval', explode(':', $input['startTime']));
                $start = $start->setTime($hour, $minute);
                if ($start < $anchor) {
                    continue;
                }
                if ($start > $maximumEnd) {
                    throw new DomainException('V3_RECURRENCE_HORIZON_EXCEEDED');
                }
                if ($start->modify('+'.$input['durationMinutes'].' minutes')->format('Y-m-d')
                    !== $start->format('Y-m-d')) {
                    throw new DomainException('V3_RECURRENCE_CROSS_MIDNIGHT');
                }

                $dateKey = $start->format('Ymd');
                $appointments[] = [
                    'appointmentKey' => $input['seriesKey'].'-'.$dateKey,
                    'visits' => [[
                        'occurrenceKey' => $input['seriesKey'].'-'.sprintf('%04d', $sequence),
                        'scheduledDate' => $start->format('Y-m-d'),
                        'scheduledTime' => $start->format('H:i'),
                        'durationMinutes' => $input['durationMinutes'],
                        'requiredWorkers' => $input['requiredWorkers'],
                    ]],
                ];
                $sequence++;
                if ($sequence > $input['occurrenceCount']) {
                    break;
                }
            }
        }

        if (count($appointments) !== $input['occurrenceCount']) {
            throw new DomainException('V3_RECURRENCE_HORIZON_EXCEEDED');
        }

        $plan = $this->visits->plan([
            'contractVersion' => 3,
            'catalogVersionKey' => $input['catalogVersionKey'],
            'catalogRevision' => $input['catalogRevision'],
            'appointments' => $appointments,
        ], $now);

        return [
            ...$plan,
            'recurrence' => [
                'seriesKey' => $input['seriesKey'],
                'frequency' => 'weekly',
                'weekdays' => $days,
                'intervalWeeks' => $input['intervalWeeks'],
                'occurrenceCount' => $input['occurrenceCount'],
            ],
            'canQuote' => false,
            'canCreateBooking' => false,
            'previewOnly' => true,
        ];
    }

    private function key(mixed $input): bool
    {
        return is_string($input)
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,49}$/D', $input) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Read-only, server-owned segmentation of a long event into consecutive
 * visits. Staff availability and prices must be verified separately.
 */
final class CleaningV3EventSchedulePreviewService
{
    public function __construct(
        private readonly CleaningV3WorkloadCapacityPlanner $capacity,
        private readonly CleaningV3VisitPlanner $visits,
    ) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(CleaningV3CatalogVersion $catalog, array $input, ?DateTimeImmutable $now = null): array
    {
        $allowed = ['appointmentKey', 'catalogVersionKey', 'catalogRevision', 'eventItemKey',
            'startDate', 'startTime', 'durationMinutes', 'requiredWorkers', 'propertyKey'];
        if (array_is_list($input) || array_diff(array_keys($input), $allowed) !== []
            || ! is_string($input['appointmentKey'] ?? null)
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,49}$/D', $input['appointmentKey']) !== 1
            || ! is_string($input['catalogVersionKey'] ?? null)
            || $input['catalogVersionKey'] !== $catalog->version_key
            || ! is_string($input['catalogRevision'] ?? null)
            || ! is_string($catalog->published_digest)
            || $catalog->status !== CleaningV3CatalogVersion::PUBLISHED
            || ! hash_equals($catalog->published_digest, $input['catalogRevision'])
            || ! is_string($input['eventItemKey'] ?? null)
            || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $input['eventItemKey']) !== 1
            || ! is_string($input['startDate'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input['startDate']) !== 1
            || ! is_string($input['startTime'] ?? null)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $input['startTime']) !== 1
            || ! is_int($input['durationMinutes'] ?? null)
            || ! is_int($input['requiredWorkers'] ?? null)
            || $input['requiredWorkers'] < 1 || $input['requiredWorkers'] > 20) {
            throw new DomainException('INVALID_V3_EVENT_SCHEDULE');
        }

        $fields = app(CleaningV3CatalogPublicSerializer::class)->serialize($catalog, 'ar')['items'];
        $fields = app(CleaningV3PropertyFormSelector::class)->select($fields, $input['propertyKey'] ?? null);
        if (! collect($fields)->contains(static fn ($field) => $field['key'] === $input['eventItemKey']
            && $field['sourceType'] === 'event_type')) {
            throw new DomainException('V3_EVENT_ITEM_UNAVAILABLE');
        }

        $tz = new DateTimeZone('Asia/Damascus');
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $input['startDate'].' '.$input['startTime'], $tz);
        if ($start === false || $start->format('Y-m-d H:i') !== $input['startDate'].' '.$input['startTime']
            || $start <= $now || $start > $now->modify('+365 days')) {
            throw new DomainException('V3_EVENT_START_INVALID');
        }
        $segments = $this->capacity->splitEventDuration($input['durationMinutes']);
        // The existing appointment contract groups visits on one local date.
        // Never truncate or silently change a requested duration at midnight.
        if ($start->modify('+'.$input['durationMinutes'].' minutes')->format('Y-m-d') !== $input['startDate']) {
            throw new DomainException('V3_EVENT_CROSS_MIDNIGHT');
        }

        $visits = [];
        $cursor = $start;
        foreach ($segments as $index => $minutes) {
            $visits[] = [
                'occurrenceKey' => $input['appointmentKey'].'-v'.sprintf('%02d', $index + 1),
                'scheduledDate' => $cursor->format('Y-m-d'),
                'scheduledTime' => $cursor->format('H:i'),
                'durationMinutes' => $minutes,
                'requiredWorkers' => $input['requiredWorkers'],
            ];
            $cursor = $cursor->modify('+'.$minutes.' minutes');
        }
        $plan = $this->visits->plan([
            'contractVersion' => 3,
            'catalogVersionKey' => $input['catalogVersionKey'],
            'catalogRevision' => $input['catalogRevision'],
            'appointments' => [['appointmentKey' => $input['appointmentKey'], 'visits' => $visits]],
        ], $now);

        return [
            ...$plan,
            'event' => [
                'eventItemKey' => $input['eventItemKey'],
                'propertyKey' => $input['propertyKey'] ?? null,
                'durationMinutes' => $input['durationMinutes'],
                'distinctCrewsRequired' => count($segments) > 1,
                'minimumDistinctWorkers' => count($segments) * $input['requiredWorkers'],
            ],
            'quoteId' => null,
            'quoteSignature' => null,
        ];
    }
}

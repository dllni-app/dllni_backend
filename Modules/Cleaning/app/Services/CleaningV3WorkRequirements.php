<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningV3CatalogItem;

/** Signed, execution-scoped workload facts. No pricing or customer metadata leaves this boundary. */
final class CleaningV3WorkRequirements
{
    public function digests(array $capacity): array
    {
        if (($capacity['checked'] ?? false) !== true || ! is_array($capacity['visits'] ?? null)) {
            throw new DomainException('V3_WORK_REQUIREMENTS_UNAVAILABLE');
        }
        $result = [];
        foreach ($capacity['visits'] as $visit) {
            $result[] = [
                'appointmentKey' => $visit['appointmentKey'],
                'occurrenceKey' => $visit['occurrenceKey'],
                'digest' => $this->digest($visit['appointmentKey'], $visit['occurrenceKey'], $visit['taskGroups'] ?? null),
            ];
        }

        return $result;
    }

    public function assertCoverage(array $snapshot): void
    {
        $digests = $snapshot['workRequirementsDigests'] ?? null;
        if (! is_array($digests) || ! array_is_list($digests) || count($digests) !== count($snapshot['visits'])) {
            throw new DomainException('V3_WORK_REQUIREMENTS_INVALID');
        }
        $seen = [];
        foreach ($digests as $entry) {
            if (! is_array($entry) || count($entry) !== 3
                || ! is_string($entry['occurrenceKey'] ?? null)
                || ! is_string($entry['appointmentKey'] ?? null)
                || ! is_string($entry['digest'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['digest']) !== 1
                || isset($seen[$entry['occurrenceKey']])) {
                throw new DomainException('V3_WORK_REQUIREMENTS_INVALID');
            }
            $identity = collect($snapshot['visits'])->firstWhere('occurrenceKey', $entry['occurrenceKey']);
            if ($identity === null || $identity['appointmentKey'] !== $entry['appointmentKey']) {
                throw new DomainException('V3_WORK_REQUIREMENTS_INVALID');
            }
            $seen[$entry['occurrenceKey']] = true;
        }
    }

    public function assertCapacity(array $snapshot, array $capacity): void
    {
        // Older archived quotes remain valid; no retroactive signing or migration.
        if (array_key_exists('workRequirementsDigests', $snapshot)) {
            $actual = $this->digests($capacity);
            $expected = $snapshot['workRequirementsDigests'];
            // Stored JSON may reorder object keys.
            if ($actual != $expected) {
                throw new DomainException('V3_WORK_REQUIREMENTS_MISMATCH');
            }
        }
    }

    /** Called only for a single authorized execution; recheck scope and visibility here. */
    public function forWorker(CleaningBooking $booking, CleaningBookingSession $execution, Worker $worker): array
    {
        $unavailable = ['requirementsAvailable' => false, 'requirements' => null];
        if ((int) auth()->id() !== (int) $worker->user_id
            || $booking->contract_version !== 'v3'
            || (int) $execution->cleaning_booking_id !== (int) $booking->id) {
            return $unavailable;
        }
        try {
            return DB::transaction(function () use ($booking, $execution, $worker, $unavailable): array {
                $current = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->first();
                if ($current === null || ! app(CleaningBookingWorkerSessionVisibilityService::class)
                    ->canViewSession($current, $execution, $worker)) {
                    return $unavailable;
                }
                $visit = app(CleaningV3DailyBookingProjection::class)->execution($current);
                if ($visit === null || (int) $visit->id !== (int) $execution->id) {
                    return $unavailable;
                }
                [$quote, $signed] = app(CleaningV3AcceptedQuoteResolver::class)->load($current, 'V3_WORK_REQUIREMENTS_UNAVAILABLE');
                $persisted = $visit->pricing_snapshot;
                if (! is_array($persisted) || ($persisted['quoteId'] ?? null) !== $quote->id) {
                    return $unavailable;
                }
                $entry = collect($signed['workRequirementsDigests'] ?? [])->firstWhere('occurrenceKey', $visit->occurrence_key);
                if ($entry === null || $entry['appointmentKey'] !== $visit->appointment_key
                    || ! hash_equals($entry['digest'], $this->digest($visit->appointment_key, $visit->occurrence_key, $persisted['taskGroups'] ?? null))) {
                    return $unavailable;
                }
                $requirements = [];
                foreach ($this->safeGroups($persisted['taskGroups']) as $group) {
                    $requirements[] = [
                        'lineId' => $visit->id.':'.hash('sha256', $group['itemKey'].'|'.$group['scopeKey']),
                        ...$group,
                    ];
                }

                return ['requirementsAvailable' => true, 'requirements' => $requirements];
            }, 3);
        } catch (DomainException) {
            return $unavailable;
        }
    }

    private function digest(string $appointment, string $occurrence, mixed $groups): string
    {
        return hash('sha256', json_encode([$appointment, $occurrence, $this->safeGroups($groups)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function safeGroups(mixed $groups): array
    {
        if (! is_array($groups) || ! array_is_list($groups) || $groups === [] || count($groups) > 40) {
            throw new DomainException('V3_WORK_REQUIREMENTS_INVALID');
        }
        $safe = [];
        foreach ($groups as $group) {
            if (! is_array($group)
                || ! is_string($group['itemKey'] ?? null) || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $group['itemKey']) !== 1
                || ! is_string($group['scopeKey'] ?? null) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,99}$/D', $group['scopeKey']) !== 1
                || ! is_string($group['label'] ?? null) || trim($group['label']) === '' || mb_strlen($group['label']) > 255
                || ! in_array($group['measurementMode'] ?? null, CleaningV3CatalogItem::MEASUREMENT_MODES, true)
                || ! is_string($group['quantity'] ?? null) || preg_match('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D', $group['quantity']) !== 1
                || bccomp($group['quantity'], '0', 2) <= 0
                || ! is_int($group['effortMinutes'] ?? null) || $group['effortMinutes'] <= 0) {
                throw new DomainException('V3_WORK_REQUIREMENTS_INVALID');
            }
            $key = $group['itemKey'].'|'.$group['scopeKey'];
            if (isset($safe[$key])) {
                throw new DomainException('V3_WORK_REQUIREMENTS_INVALID');
            }
            // Fixed order, explicit allowlist. Never include monetary fields,
            // property answers, customer details or arbitrary snapshot keys.
            $safe[$key] = [
                'itemKey' => $group['itemKey'], 'scopeKey' => $group['scopeKey'],
                'label' => $group['label'], 'measurementMode' => $group['measurementMode'],
                'quantity' => $group['quantity'], 'effortMinutes' => $group['effortMinutes'],
            ];
        }
        ksort($safe, SORT_STRING);

        return array_values($safe);
    }
}

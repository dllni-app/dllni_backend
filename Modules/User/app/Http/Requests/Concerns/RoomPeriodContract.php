<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Cleaning\Models\CleaningRoomType;

/**
 * Shared contract for the next-version room-period estimate and store payloads.
 * Shared V2 contract for authoritative room-period estimate and create.
 * Legacy booking-level extras cannot silently override per-period selections.
 */
final class RoomPeriodContract
{
    public static function rules(): array
    {
        $today = now(config('app.timezone'))->toDateString();

        return [
            'quoteSignature' => ['sometimes', 'string', 'size:64'],
            'roomPeriods' => ['sometimes', 'array', 'min:1', 'max:30'],
            'roomPeriods.*' => ['required', 'array:clientKey,visitSequence,roomType,size,cleaningMode,estimatedHours,scheduledDate,scheduledTime,numberOfWorkers,materials,specialServices,notes'],
            'roomPeriods.*.clientKey' => ['required', 'string', 'max:68', 'regex:/^[a-z][a-z0-9_-]{0,63}\.[1-9][0-9]{0,2}$/D'],
            'roomPeriods.*.visitSequence' => ['required', 'integer', 'min:1', 'max:30'],
            'roomPeriods.*.roomType' => ['required', Rule::in(CleaningRoomType::availableCodes())],
            'roomPeriods.*.size' => ['required', Rule::in(['small', 'medium', 'large'])],
            'roomPeriods.*.cleaningMode' => ['required', Rule::in(['regular', 'deep'])],
            'roomPeriods.*.estimatedHours' => ['sometimes', 'numeric', 'min:0.25', 'max:24'],
            'roomPeriods.*.scheduledDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'roomPeriods.*.scheduledTime' => ['required', 'date_format:H:i'],
            'roomPeriods.*.numberOfWorkers' => ['required', 'integer', 'min:1', 'max:20'],
            'roomPeriods.*.materials' => ['required', 'array:providedByPlatform,materialTypeIds'],
            'roomPeriods.*.materials.providedByPlatform' => ['required', 'boolean'],
            'roomPeriods.*.materials.materialTypeIds' => ['present', 'array', 'max:64'],
            'roomPeriods.*.materials.materialTypeIds.*' => ['integer', 'distinct', 'exists:cleaning_material_types,id'],
            'roomPeriods.*.specialServices' => ['present', 'array', 'max:10'],
            'roomPeriods.*.specialServices.*' => ['required', 'array:specialServiceId,quantity,dirtinessLevel,notes'],
            'roomPeriods.*.specialServices.*.specialServiceId' => ['required', 'integer', 'exists:cleaning_special_services,id'],
            'roomPeriods.*.specialServices.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'roomPeriods.*.specialServices.*.dirtinessLevel' => ['nullable', 'string', 'max:64'],
            'roomPeriods.*.specialServices.*.notes' => ['nullable', 'string', 'max:2000'],
            'roomPeriods.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function validateConsistency(array $data, Validator $validator): void
    {
        $periods = $data['roomPeriods'] ?? null;
        if (! is_array($periods)) {
            return;
        }

        if (($data['propertyType'] ?? '') === 'event_assistance'
            || in_array((string) ($data['bookingKind'] ?? 'standard'), ['open_time', 'special_service'], true)
            || isset($data['openTime'])
            || isset($data['event'])
        ) {
            $validator->errors()->add('roomPeriods', 'Room periods are supported only for standard cleaning.');
        }

        foreach (['materials', 'requestMaterials', 'specialServices', 'workerRoomAssignments'] as $legacyField) {
            if (array_key_exists($legacyField, $data)) {
                $validator->errors()->add($legacyField, 'Use roomPeriods for per-period selections; do not mix with legacy booking-level extras.');
            }
        }

        $schedule = $data['schedule'] ?? null;
        if (is_array($schedule)
            && (mb_strtolower((string) ($schedule['mode'] ?? 'one_time')) === 'recurring'
                || ! empty($schedule['sessions'])
                || ! empty($schedule['recurringSessions']))) {
            $validator->errors()->add(
                'schedule',
                'Recurring schedules require explicit per-visit room periods and are not supported yet.'
            );
        }

        // Recurrence has visit-level schedule and billing rules not yet wired
        // to room periods; never silently materialize orphan visit sequences.
        if (collect($periods)->contains(static fn ($p): bool => is_array($p) && (int) ($p['visitSequence'] ?? 0) !== 1
        )) {
            $validator->errors()->add('roomPeriods', 'Room periods for recurring visits are not available yet.');
        }

        if (($data['assignmentMode'] ?? 'open_count') === 'preferred_worker'
            || ! empty($data['preferredWorkerId']) || ! empty($data['preferredWorkerIds'])) {
            $validator->errors()->add('assignmentMode', 'Individual preferred-worker selection is not yet available for room periods.');
        }

        $seen = [];
        $roomsByVisit = [];
        foreach ($periods as $idx => $period) {
            if (! is_array($period)) {
                continue;
            }
            $key = $period['clientKey'] ?? null;
            $sequence = $period['visitSequence'] ?? null;
            if (! is_string($key) || ! is_numeric($sequence)) {
                continue;
            }
            $identity = (int) $sequence.':'.$key;
            if (isset($seen[$identity])) {
                $validator->errors()->add("roomPeriods.$idx.clientKey", 'Duplicate room period in the same visit.');
            }
            $seen[$identity] = true;
            if (preg_match('/^([a-z][a-z0-9_-]{0,63})\.([1-9][0-9]{0,2})$/D', $key, $parts)) {
                if (($period['roomType'] ?? '') !== $parts[1]) {
                    $validator->errors()->add("roomPeriods.$idx.roomType", 'Room type must match the stable client key.');
                }
                $roomsByVisit[(int) $sequence][$key] = true;
            }
        }

        // A standard request must configure the complete room set on every visit.
        $breakdown = $data['propertyDetails']['room_size_breakdown'] ?? [];
        if (! is_array($breakdown)) {
            return;
        }
        $expected = [];
        foreach (CleaningRoomType::availableCodes() as $type) {
            $counts = $breakdown[$type] ?? [];
            if (! is_array($counts)) {
                continue;
            }
            $number = array_sum(array_map('intval', [
                $counts['small'] ?? 0, $counts['medium'] ?? 0, $counts['large'] ?? 0,
            ]));
            for ($i = 1; $i <= min($number, 30); $i++) {
                $expected[$type.'.'.$i] = true;
            }
        }

        if ($expected === []) {
            $validator->errors()->add('roomPeriods', 'Select at least one room before configuring periods.');
        }
        // The size breakdown is a customer-selected configuration, not just
        // a room-count hint. A room-period payload must match its size totals.
        $actualSizeCounts = [];
        foreach ($periods as $period) {
            if (! is_array($period) || (int) ($period['visitSequence'] ?? 0) !== 1) {
                continue;
            }
            $type = $period['roomType'] ?? '';
            $size = $period['size'] ?? '';
            if (is_string($type) && is_string($size)) {
                $actualSizeCounts[$type][$size] = ($actualSizeCounts[$type][$size] ?? 0) + 1;
            }
        }
        foreach (CleaningRoomType::availableCodes() as $type) {
            foreach (['small', 'medium', 'large'] as $size) {
                if ((int) ($actualSizeCounts[$type][$size] ?? 0)
                    !== (int) ($breakdown[$type][$size] ?? 0)) {
                    $validator->errors()->add('roomPeriods', 'Room period sizes must match the selected room sizes.');
                }
            }
        }

        foreach ($roomsByVisit as $visit => $keys) {
            if (array_diff_key($expected, $keys) !== [] || array_diff_key($keys, $expected) !== []) {
                $validator->errors()->add('roomPeriods', "Visit $visit must contain exactly one period per selected room.");
            }
        }
    }
}

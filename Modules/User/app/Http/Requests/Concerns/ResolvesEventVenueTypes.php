<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Modules\Cleaning\Models\CleaningEventType;
use Modules\User\Services\UserCleaningOrderEstimationService;

/**
 * Share one venue-value contract between UI config, quote and booking validation.
 * Legacy residential values remain available for older clients.
 */
trait ResolvesEventVenueTypes
{
    /** @return list<string> */
    private function availableVenueTypes(): array
    {
        $legacy = array_values(array_filter(
            UserCleaningOrderEstimationService::PROPERTY_TYPES,
            static fn (string $type): bool => $type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE
        ));

        $typeId = (int) $this->input(
            'event.eventTypeId',
            $this->input('propertyDetails.eventTypeId', 0)
        );
        if ($typeId <= 0) {
            return $legacy;
        }

        $type = CleaningEventType::query()
            ->where('is_active', true)
            ->with(['fields' => static fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('key', ['venue_type', 'venueType', 'event_venue'])])
            ->find($typeId);

        if (! $type instanceof CleaningEventType) {
            return $legacy;
        }

        $additional = [];
        foreach ($type->fields as $field) {
            foreach ((array) ($field->options ?? []) as $option) {
                $value = is_array($option)
                    ? ($option['value'] ?? $option['id'] ?? null)
                    : $option;
                if (! is_scalar($value)) {
                    continue;
                }
                $value = mb_trim((string) $value);
                if ($value !== '') {
                    $additional[] = $value;
                }
            }
        }

        return array_values(array_unique([...$legacy, ...$additional]));
    }
}

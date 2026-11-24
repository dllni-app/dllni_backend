<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/** Explicit property membership is opt-in per catalog; legacy flat catalogs stay compatible. */
final class CleaningV3PropertyFormSelector
{
    public function validKeys(mixed $keys): bool
    {
        if (! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > 100) {
            return false;
        }
        foreach ($keys as $key) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $key) !== 1) {
                return false;
            }
        }

        return count(array_unique($keys)) === count($keys);
    }

    /** @param list<array<string,mixed>> $fields */
    public function issues(array $fields): array
    {
        $properties = array_column(array_filter($fields, static fn ($field) => $field['sourceType'] === 'property'), 'key');
        $issues = [];
        foreach ($fields as $field) {
            if (! ($field['propertyScopeConfigured'] ?? false)) {
                continue;
            }
            if ($field['sourceType'] === 'property' || ! $this->validKeys($field['propertyKeys'] ?? null)
                || array_diff($field['propertyKeys'], $properties) !== []) {
                $issues[] = 'INVALID_PROPERTY_MEMBERSHIP:'.$field['key'];
            }
        }

        return $issues;
    }

    /** @param list<array<string,mixed>> $fields */
    public function select(array $fields, mixed $propertyKey): array
    {
        $grouped = array_filter($fields, static fn ($field) => $field['propertyScopeConfigured'] ?? false) !== [];
        if (! $grouped) {
            if ($propertyKey !== null) {
                throw new CleaningV3VisitPlanException('propertyKey', 'V3_PROPERTY_NOT_SUPPORTED');
            }

            return $fields;
        }
        if ($this->issues($fields) !== []) {
            throw new CleaningV3VisitPlanException('catalog', 'V3_PROPERTY_CONFIGURATION_INVALID');
        }
        $properties = array_column(array_filter($fields, static fn ($field) => $field['sourceType'] === 'property'), 'key');
        if (! is_string($propertyKey) || ! in_array($propertyKey, $properties, true)) {
            throw new CleaningV3VisitPlanException('propertyKey', 'V3_PROPERTY_REQUIRED');
        }

        return array_values(array_filter($fields, static fn ($field) =>
            $field['sourceType'] === 'property'
                ? $field['key'] === $propertyKey
                : (! ($field['propertyScopeConfigured'] ?? false) || in_array($propertyKey, $field['propertyKeys'], true))
        ));
    }
}

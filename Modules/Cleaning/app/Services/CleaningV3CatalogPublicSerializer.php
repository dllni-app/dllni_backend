<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Sanitizes a published catalog snapshot for a public, non-financial read.
 * Never returns V2 tariffs, booking data, internal input_rules or drafts.
 */
final class CleaningV3CatalogPublicSerializer
{
    public const SUPPORTED_COMPONENT_IDS = [
        'text', 'numeric', 'stepper', 'select', 'multiselect', 'room_builder',
        'property_builder', 'location', 'date_time', 'recurrence_preview', 'addons', 'summary',
    ];

    public function serialize(CleaningV3CatalogVersion $version, string $locale): array
    {
        $version->loadMissing('items.presets');
        $items = $version->items
            ->where('is_enabled', true)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn ($item): array => $this->item($item, $locale))
            ->values()
            ->all();

        return [
            'schemaVersion' => 3,
            'rendererRevision' => 1,
            'catalogVersionKey' => (string) $version->version_key,
            'catalogRevision' => (string) $version->published_digest,
            'locale' => $locale,
            'capabilities' => [
                'readOnlyPreview' => true,
                'quoteEnabled' => false,
                'createEnabled' => false,
                'serverSupportedComponents' => self::SUPPORTED_COMPONENT_IDS,
                'minimumRendererRevision' => 1,
            ],
            'items' => $items,
        ];
    }

    private function item(mixed $item, string $locale): array
    {
        $rules = is_array($item->input_rules) ? $item->input_rules : [];
        $defaultComponent = match ((string) $item->measurement_mode) {
            'count', 'piece' => 'stepper',
            'sqm', 'linear_meter', 'hour' => 'numeric',
            default => 'text',
        };
        $component = (string) ($rules['componentId'] ?? $defaultComponent);
        // Unknown/conditional rules must NOT silently disappear from a public
        // schema and turn a mandatory control into an unvalidated answer.
        $knownRuleKeys = ['componentId', 'descriptionAr', 'descriptionEn', 'min', 'max', 'step', 'maxSelections'];
        $unsupportedRules = array_diff(array_keys($rules), $knownRuleKeys) !== [];
        $label = $locale === 'en' && filled($item->label_en)
            ? (string) $item->label_en : (string) $item->label_ar;
        $description = $rules[$locale === 'en' ? 'descriptionEn' : 'descriptionAr']
            ?? $rules['descriptionAr'] ?? null;

        $constraints = [];
        foreach (['min', 'max', 'step'] as $name) {
            if (isset($rules[$name]) && is_numeric($rules[$name])
                && is_finite((float) $rules[$name])) {
                $constraints[$name] = (float) $rules[$name];
            }
        }
        if (isset($rules['maxSelections']) && is_int($rules['maxSelections'])) {
            $constraints['maxSelections'] = max(0, $rules['maxSelections']);
        }

        $options = $item->presets->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(static fn ($preset): array => [
                'key' => (string) $preset->preset_key,
                'label' => $locale === 'en' && filled($preset->label_en)
                    ? (string) $preset->label_en : (string) $preset->label_ar,
                'minimumValue' => (float) $preset->minimum_value,
                'maximumValue' => (float) $preset->maximum_value,
            ])->values()->all();

        return [
            'key' => (string) $item->item_key,
            'componentId' => $component,
            'label' => $label,
            'description' => is_string($description) ? $description : null,
            'sourceType' => (string) $item->source_type,
            'sourceId' => (int) $item->source_id,
            'required' => (bool) $item->required,
            'measurementMode' => (string) $item->measurement_mode,
            'constraints' => $constraints,
            'choices' => $options,
            'mediaUrl' => null,
            'supportedByServer' => in_array($component, self::SUPPORTED_COMPONENT_IDS, true) && ! $unsupportedRules,
            'unsupportedRules' => $unsupportedRules,
        ];
    }
}

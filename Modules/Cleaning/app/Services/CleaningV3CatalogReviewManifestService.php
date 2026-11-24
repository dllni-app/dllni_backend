<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Deterministic, read-only review evidence for an unpublished V3 catalog.
 * This fingerprint is NOT a signed publication digest or a financial approval.
 */
final class CleaningV3CatalogReviewManifestService
{
    public function __construct(
        private readonly CleaningV3CatalogDraftValidator $validator,
    ) {}

    /** @return array{catalogVersionKey:string,fingerprint:string,itemCount:int,tariffRuleCount:int,issueCodes:list<string>,readyForPublication:bool} */
    public function inspect(CleaningV3CatalogVersion $version): array
    {
        if (! $version->exists || $version->status !== CleaningV3CatalogVersion::DRAFT) {
            throw new DomainException('V3_REVIEW_REQUIRES_DRAFT');
        }

        $version->load('items.presets', 'items.tariffRules');
        $items = [];
        $tariffCount = 0;
        foreach ($version->items->sortBy('item_key') as $item) {
            $presets = [];
            foreach ($item->presets->sortBy('preset_key') as $preset) {
                $presets[] = [
                    'key' => (string) $preset->preset_key,
                    'labelAr' => (string) $preset->label_ar,
                    'labelEn' => (string) $preset->label_en,
                    'min' => (string) $preset->minimum_value,
                    'max' => (string) $preset->maximum_value,
                    'sort' => (int) $preset->sort_order,
                ];
            }

            $tariffs = [];
            foreach ($item->tariffRules->sortBy('rule_key') as $rule) {
                $tariffCount++;
                $tariffs[] = [
                    'key' => (string) $rule->rule_key,
                    'mode' => (string) $rule->cleaning_mode,
                    'scope' => (string) $rule->scope_key,
                    'min' => (string) $rule->quantity_min,
                    'max' => (string) $rule->quantity_max,
                    'unitPrice' => (string) $rule->unit_price,
                    'unitEffortMinutes' => (string) $rule->unit_effort_minutes,
                    'currency' => (string) $rule->currency,
                    'enabled' => (bool) $rule->is_enabled,
                    'sort' => (int) $rule->sort_order,
                ];
            }
            $rules = is_array($item->input_rules) ? $item->input_rules : [];
            $items[] = [
                'key' => (string) $item->item_key,
                'sourceType' => (string) $item->source_type,
                'sourceId' => (int) $item->source_id,
                'labelAr' => (string) $item->label_ar,
                'labelEn' => (string) $item->label_en,
                'measurementMode' => (string) $item->measurement_mode,
                'inputRules' => $this->canonical($rules),
                'required' => (bool) $item->required,
                'enabled' => (bool) $item->is_enabled,
                'sort' => (int) $item->sort_order,
                'presets' => $presets,
                'tariffs' => $tariffs,
            ];
        }

        $snapshot = [
            'schemaVersion' => 3,
            'catalogVersionKey' => (string) $version->version_key,
            'items' => $items,
        ];
        $issues = $this->validator->review($version);
        sort($issues, SORT_STRING);

        return [
            'catalogVersionKey' => (string) $version->version_key,
            'fingerprint' => hash('sha256', json_encode(
                $snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            'itemCount' => count($items),
            'tariffRuleCount' => $tariffCount,
            'issueCodes' => $issues,
            // Explicitly false until separately approved publication and funding exist.
            'readyForPublication' => false,
        ];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }
}

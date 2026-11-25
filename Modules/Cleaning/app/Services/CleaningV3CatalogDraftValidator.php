<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

final class CleaningV3CatalogDraftValidator
{
    /** @var array<string, class-string<Model>> */
    private const SOURCES = [
        'property' => CleaningHomeType::class,
        'room' => CleaningRoomType::class,
        'service' => CleaningService::class,
        'special_service' => CleaningSpecialService::class,
        'material' => CleaningMaterialType::class,
        'equipment' => CleaningSpecialServiceEquipment::class,
        'event_type' => CleaningEventType::class,
    ];

    public function assertActiveSource(string $type, int $id): void
    {
        $model = self::SOURCES[$type] ?? null;
        if ($model === null || $id < 1 || ! $model::query()->whereKey($id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'source_id' => 'The item must reference an active record in the existing cleaning catalog.',
            ]);
        }
    }

    /**
     * Returns problems instead of ever silently publishing an incomplete tariff.
     *
     * @return list<string>
     */
    public function review(CleaningV3CatalogVersion $version): array
    {
        $problems = [];
        $items = $version->items()->with(['presets', 'tariffRules'])->orderBy('sort_order')->get();

        if ($items->isEmpty()) {
            $problems[] = 'CATALOG_EMPTY';
        }

        $equipmentIds = [];
        foreach ($items as $item) {
            $inputRules = is_array($item->input_rules) ? $item->input_rules : [];
            $allowedInputRules = ['componentId', 'descriptionAr', 'descriptionEn', 'min', 'max', 'step', 'maxSelections', 'scopeLabels', 'propertyKeys', 'equipmentAssetIds', 'equipmentUnitPriceSyp', 'splitUnitQuantity'];
            if (array_diff(array_keys($inputRules), $allowedInputRules) !== []) {
                $problems[] = 'UNSUPPORTED_INPUT_RULES:'.$item->item_key;
            }
            if ($item->required && isset($inputRules['componentId'])
                && ! in_array($inputRules['componentId'], ['text', 'numeric', 'stepper', 'select', 'multiselect'], true)) {
                $problems[] = 'REQUIRED_COMPONENT_UNSUPPORTED:'.$item->item_key;
            }
            foreach (['min', 'max', 'step'] as $field) {
                if (isset($inputRules[$field])
                    && (! is_numeric($inputRules[$field])
                        || ! is_finite((float) $inputRules[$field]))) {
                    $problems[] = 'INVALID_INPUT_CONSTRAINTS:'.$item->item_key;
                }
            }
            if ((isset($inputRules['min'], $inputRules['max'])
                    && is_numeric($inputRules['min']) && is_numeric($inputRules['max'])
                    && (float) $inputRules['max'] <= (float) $inputRules['min'])
                || (isset($inputRules['step']) && is_numeric($inputRules['step'])
                    && (float) $inputRules['step'] <= 0)
                || (isset($inputRules['maxSelections'])
                    && (! is_int($inputRules['maxSelections']) || $inputRules['maxSelections'] < 1))) {
                $problems[] = 'INVALID_INPUT_CONSTRAINTS:'.$item->item_key;
            }

            try {
                $this->assertActiveSource((string) $item->source_type, (int) $item->source_id);
            } catch (ValidationException) {
                $problems[] = 'INVALID_SOURCE:'.$item->item_key;
            }

            $presets = $item->presets->sortBy('minimum_value')->values();
            $lastMaximum = null;
            foreach ($presets as $preset) {
                if ((float) $preset->maximum_value <= (float) $preset->minimum_value) {
                    $problems[] = 'INVALID_PRESET:'.$item->item_key;
                }
                if ($lastMaximum !== null && (float) $preset->minimum_value < $lastMaximum) {
                    $problems[] = 'OVERLAPPING_PRESETS:'.$item->item_key;
                }
                $lastMaximum = (float) $preset->maximum_value;
            }

            if (! $item->is_enabled) {
                continue;
            }

            if (! app(CleaningV3TaskSplitPolicy::class)->valid($item)) {
                $problems[] = 'INVALID_TASK_SPLIT_POLICY:'.$item->item_key;
            }
            if ($item->source_type === 'equipment') {
                $config = app(CleaningV3EquipmentPreviewService::class)->configuration($item);
                if ($config === null) {
                    $problems[] = 'INVALID_EQUIPMENT_CONFIGURATION:'.$item->item_key;
                } else {
                    foreach ($config['assetIds'] as $assetId) {
                        if (isset($equipmentIds[$assetId])) {
                            $problems[] = 'OVERLAPPING_EQUIPMENT_POOL:'.$item->item_key;
                        }
                        $equipmentIds[$assetId] = true;
                    }
                    if (CleaningSpecialServiceEquipment::query()->whereIn('id', $config['assetIds'])->where('is_active', true)->count() !== count($config['assetIds'])) {
                        $problems[] = 'INVALID_EQUIPMENT_ASSET:'.$item->item_key;
                    }
                }
                continue;
            }
            if (array_intersect(array_keys($inputRules), ['equipmentAssetIds', 'equipmentUnitPriceSyp']) !== []) {
                $problems[] = 'INVALID_EQUIPMENT_CONFIGURATION:'.$item->item_key;
            }
            $rules = $item->tariffRules->where('is_enabled', true);
            if ($item->source_type === 'event_type' && ($item->measurement_mode !== 'hour'
                || $rules->contains(static fn ($rule): bool => $rule->cleaning_mode !== 'regular'
                    || $rule->scope_key !== 'whole' || (float) $rule->unit_effort_minutes !== 60.0))) {
                $problems[] = 'INVALID_EVENT_HOURLY_TARIFF:'.$item->item_key;
            }
            $scopeCatalog = app(CleaningV3DeepScopeCatalog::class);
            if (! $scopeCatalog->validLabels($inputRules['scopeLabels'] ?? [])) {
                $problems[] = 'INVALID_SCOPE_LABELS:'.$item->item_key;
            }
            $visibleScopes = array_column($scopeCatalog->choices($item), 'key');
            if ($rules->where('cleaning_mode', 'deep')->whereNotIn('scope_key', $visibleScopes)->isNotEmpty()) {
                $problems[] = 'DEEP_SCOPE_LABEL_MISSING:'.$item->item_key;
            }
            if ($rules->isEmpty()) {
                $problems[] = 'TARIFF_MISSING:'.$item->item_key;
            }

            // Overlapping (mode, scope, quantity) rules would let the same
            // client answer match two different prices or effort estimates.
            // Gaps are equally unsafe and must be resolved before publishing.
            foreach ($rules->groupBy(
                static fn ($rule): string => $rule->cleaning_mode.'|'.$rule->scope_key
            ) as $group) {
                $previousMax = null;
                // Client constraints are inclusive, while tariff upper bounds
                // are exclusive. Verify BOTH edges, not just gaps in between.
                $sorted = $group->sortBy('quantity_min', SORT_NUMERIC)->values();
                $minimum = $inputRules['min'] ?? null;
                $maximum = $inputRules['max'] ?? null;
                $hasMinimum = is_numeric($minimum) && is_finite((float) $minimum)
                    && (float) $minimum >= 0;
                $hasMaximum = is_numeric($maximum) && is_finite((float) $maximum)
                    && (float) $maximum > 0;
                if ($hasMinimum && (float) $sorted->first()->quantity_min > (float) $minimum) {
                    $problems[] = 'TARIFF_START_GAP:'.$item->item_key;
                }
                if ($hasMaximum && (float) $sorted->max('quantity_max') <= (float) $maximum) {
                    $problems[] = 'TARIFF_END_GAP:'.$item->item_key;
                }
                foreach ($sorted as $rule) {
                    $min = (float) $rule->quantity_min;
                    $max = (float) $rule->quantity_max;
                    $price = (float) $rule->unit_price;
                    $effort = (float) $rule->unit_effort_minutes;
                    if ($min < 0 || $max <= $min || $price <= 0
                        || $effort <= 0 || $effort > 480 || $rule->currency !== 'SYP') {
                        $problems[] = 'INVALID_TARIFF:'.$item->item_key;
                    }
                    if ($previousMax !== null) {
                        if ($min < $previousMax) {
                            $problems[] = 'OVERLAPPING_TARIFFS:'.$item->item_key;
                        } elseif ($min > $previousMax) {
                            $problems[] = 'TARIFF_GAP:'.$item->item_key;
                        }
                    }
                    $previousMax = max($previousMax ?? 0.0, $max);
                }
            }
        }

        $publicFields = app(CleaningV3CatalogPublicSerializer::class)->serialize($version, 'ar')['items'];
        $problems = [...$problems, ...app(CleaningV3PropertyFormSelector::class)->issues($publicFields)];

        // An approved tariff and funding contract must be imported explicitly.
        $problems[] = 'APPROVED_CATALOG_COVERAGE_MATRIX_MISSING';
        $problems[] = 'APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING';

        return array_values(array_unique($problems));
    }

    public function publish(CleaningV3CatalogVersion $version): never
    {
        $version->assertEditable();

        throw new DomainException(
            'V3 catalog publication is blocked pending approved coverage, pricing, refund and funding evidence.'
        );
    }
}

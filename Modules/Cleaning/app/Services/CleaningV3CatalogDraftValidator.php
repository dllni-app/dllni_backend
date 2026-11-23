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

        foreach ($items as $item) {
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

            $rules = $item->tariffRules->where('is_enabled', true);
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
                foreach ($group->sortBy('quantity_min', SORT_NUMERIC) as $rule) {
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

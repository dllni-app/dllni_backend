<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningDirtinessLevel;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningSpecialServiceCategory;

final class CleaningSuiteConfigController
{
    public function __invoke(): JsonResponse
    {
        $policy = CleaningBillingPolicy::query()
            ->where('is_active', true)
            ->where('billing_mode', 'actual_working_time')
            ->orderByDesc('is_default')
            ->first();
        $rules = is_array($policy?->rules) ? $policy->rules : [];

        $categories = CleaningSpecialServiceCategory::query()
            ->where('is_active', true)
            ->with(['services' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['dirtinessLevels' => fn ($levels) => $levels->where('is_active', true)->orderBy('sort_order')])])
            ->orderBy('sort_order')
            ->get();

        $uncategorized = \Modules\Cleaning\Models\CleaningSpecialService::query()
            ->whereNull('cleaning_special_service_category_id')
            ->where('is_active', true)
            ->with(['dirtinessLevels' => fn ($levels) => $levels->where('is_active', true)->orderBy('sort_order')])
            ->get();

        $servicePayload = static fn ($service): array => [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'description' => $service->description,
            'imageUrl' => $service->imageUrl(),
            'inputType' => (string) $service->input_type,
            'pricingUnit' => (string) $service->pricing_unit,
            'unitCode' => $service->unit_code,
            'baseUnitPrice' => (float) $service->base_unit_price,
            'supportsDirtiness' => (bool) $service->supports_dirtiness,
            'genderConstraint' => $service->gender_constraint,
            'estimatedDurationMinutes' => (int) $service->estimated_duration_minutes,
            'requiresBeforeImage' => (bool) $service->requires_before_image,
            'requiresAfterImage' => (bool) $service->requires_after_image,
            'dirtinessLevels' => $service->dirtinessLevels->map(static fn ($level): array => [
                'id' => (int) $level->id,
                'name' => (string) $level->name,
                'slug' => (string) $level->slug,
                'priceMultiplier' => (float) $level->price_multiplier,
            ])->values()->all(),
        ];

        return response()->json(['success' => true, 'data' => [
            'schemaVersion' => 2,
            'serverNow' => now()->toIso8601String(),
            'capabilities' => [
                'materialTypes' => true,
                'materialKits' => true,
                'specialServiceItems' => true,
                'recurringSpecialServices' => true,
                'dynamicEventTypes' => true,
                'openTimeExtensions' => true,
                'scheduleChangeApprovals' => true,
            ],
            'openTime' => [
                'durationOptions' => array_values((array) ($rules['duration_options'] ?? [60, 120, 180, 240, 480])),
                'extensionOptions' => array_values((array) ($rules['extension_options'] ?? [15, 30, 60])),
                'legacyDefaultMinutes' => 480,
                'hardMaxMinutes' => (int) ($rules['hard_max_minutes'] ?? 480),
                'warningMinutes' => (int) ($rules['warning_minutes'] ?? 30),
                'minimumBillableMinutes' => (int) ($rules['min_billable_minutes'] ?? 60),
                'roundingMinutes' => (int) ($rules['rounding_minutes'] ?? 15),
            ],
            'dirtinessLevels' => CleaningDirtinessLevel::query()
                ->where('is_active', true)->orderBy('sort_order')->get()
                ->map(static fn ($level): array => [
                    'id' => (int) $level->id,
                    'name' => (string) $level->name,
                    'slug' => (string) $level->slug,
                    'priceMultiplier' => (float) $level->price_multiplier,
                ])->values()->all(),
            'specialServiceCategories' => $categories->map(static fn ($category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'services' => $category->services->map($servicePayload)->values()->all(),
            ])->values()->all(),
            'uncategorizedSpecialServices' => $uncategorized->map($servicePayload)->values()->all(),
            'eventTypes' => CleaningEventType::query()
                ->where('is_active', true)
                ->with([
                    'fields' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
                    'specialServices' => fn ($query) => $query->where('is_active', true),
                ])
                ->orderBy('sort_order')
                ->get()
                ->map(static fn ($type): array => [
                    'id' => (int) $type->id,
                    'name' => (string) $type->name,
                    'slug' => (string) $type->slug,
                    'fields' => $type->fields->map(static fn ($field): array => [
                        'id' => (int) $field->id,
                        'key' => (string) $field->key,
                        'label' => (string) $field->label,
                        'type' => (string) $field->field_type,
                        'required' => (bool) $field->is_required,
                        'options' => $field->options ?? [],
                    ])->values()->all(),
                    'specialServiceIds' => $type->specialServices->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                ])->values()->all(),
        ]]);
    }
}

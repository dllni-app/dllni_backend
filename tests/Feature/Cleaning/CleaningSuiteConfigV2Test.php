<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningDirtinessLevel;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningEventTypeField;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialTypeQuantityRule;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceCategory;

use function Pest\Laravel\getJson;

it('publishes dynamic event and special-service configuration with v2 capabilities', function (): void {
    $category = CleaningSpecialServiceCategory::query()->create([
        'name' => 'Furniture', 'slug' => 'furniture', 'sort_order' => 1, 'is_active' => true,
    ]);
    $level = CleaningDirtinessLevel::query()->create([
        'name' => 'Heavy', 'slug' => 'heavy-test', 'price_multiplier' => 1.5, 'sort_order' => 1, 'is_active' => true,
    ]);
    $service = CleaningSpecialService::query()->create([
        'cleaning_special_service_category_id' => $category->id,
        'name' => 'Sofa', 'slug' => 'sofa-test', 'pricing_unit' => 'sofa',
        'input_type' => 'decimal', 'base_unit_price' => 100,
        'supports_dirtiness' => true, 'estimated_duration_minutes' => 45,
        'worker_pay_mode' => 'percentage', 'worker_pay_value' => 50,
        'operating_cost_mode' => 'flat', 'operating_cost_value' => 10,
        'travel_fee_mode' => 'flat', 'travel_fee_value' => 0,
        'is_active' => true,
    ]);
    $service->dirtinessLevels()->attach($level->id);
    $event = CleaningEventType::query()->create([
        'name' => 'Wedding', 'slug' => 'wedding-test', 'sort_order' => 1, 'is_active' => true,
    ]);
    CleaningEventTypeField::query()->create([
        'cleaning_event_type_id' => $event->id,
        'label' => 'Guests', 'key' => 'guest_count', 'field_type' => 'number',
        'is_required' => true, 'sort_order' => 1, 'is_active' => true,
    ]);
    $event->specialServices()->attach($service->id);

    getJson('/api/v1/cleaning/suite-config')
        ->assertOk()
        ->assertJsonPath('data.schemaVersion', 2)
        ->assertJsonPath('data.serverNow', fn (mixed $value): bool => is_string($value) && str_contains($value, 'T'))
        ->assertJsonPath('data.capabilities.dynamicEventTypes', true)
        ->assertJsonFragment(['slug' => 'wedding-test'])
        ->assertJsonFragment(['key' => 'guest_count', 'type' => 'number', 'required' => true])
        ->assertJsonFragment(['specialServiceIds' => [$service->id]])
        ->assertJsonFragment(['inputType' => 'decimal'])
        ->assertJsonFragment(['slug' => 'heavy-test', 'priceMultiplier' => 1.5]);

    getJson('/api/v1/cleaning-services?filter[category]=special_service&perPage=100')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $service->id,
            'inputType' => 'decimal',
            'supportsDirtiness' => true,
            'estimatedDurationMinutes' => 45,
        ])
        ->assertJsonFragment([
            'id' => $level->id,
            'slug' => 'heavy-test',
            'priceMultiplier' => 1.5,
        ]);
});


it('publishes only active material types with unit, available stock and automatic quantity rules', function (): void {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Liter', 'code' => 'liter-suite', 'symbol' => 'L', 'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Floor cleaner', 'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 12, 'is_active' => true,
    ]);
    CleaningMaterial::query()->create([
        'name' => 'Floor cleaner stock', 'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 4.5, 'low_stock_threshold' => 1, 'is_active' => true,
    ]);
    CleaningMaterialTypeQuantityRule::query()->create([
        'cleaning_material_type_id' => $type->id,
        'room_type' => 'bedroom', 'room_size' => 'small', 'cleaning_mode' => 'regular',
        'quantity_per_room' => 0.25, 'is_active' => true, 'requires_admin_resolution' => false,
    ]);

    getJson('/api/v1/cleaning/suite-config')
        ->assertOk()
        ->assertJsonPath('data.materialTypes.0.id', $type->id)
        ->assertJsonPath('data.materialTypes.0.unitCode', 'liter-suite')
        ->assertJsonPath('data.materialTypes.0.availableQuantity', 4.5)
        ->assertJsonPath('data.materialTypes.0.quantityRules.0.quantityPerRoom', 0.25);
});

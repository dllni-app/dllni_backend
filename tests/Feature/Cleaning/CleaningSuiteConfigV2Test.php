<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningDirtinessLevel;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningEventTypeField;
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

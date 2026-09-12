<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialQuantityRule;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceDirtinessRule;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningMaterialQuoteService;
use Modules\Cleaning\Services\CleaningSpecialServiceQuoteService;

it('uses the most specific configured material quantity rule', function (): void {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Liter',
        'code' => 'liter-'.fake()->unique()->numerify('####'),
        'symbol' => 'L',
        'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Liquid '.fake()->unique()->numerify('####'),
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 10,
        'is_active' => true,
    ]);
    $material = CleaningMaterial::query()->create([
        'name' => 'Cleaner '.fake()->unique()->numerify('####'),
        'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 100,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    CleaningMaterialQuantityRule::query()->create([
        'cleaning_material_id' => $material->id,
        'room_type' => null,
        'room_size' => null,
        'cleaning_mode' => null,
        'quantity_per_room' => 0.25,
        'is_active' => true,
    ]);
    CleaningMaterialQuantityRule::query()->create([
        'cleaning_material_id' => $material->id,
        'room_type' => 'bedroom',
        'room_size' => 'large',
        'cleaning_mode' => 'deep',
        'quantity_per_room' => 1.5,
        'is_active' => true,
    ]);

    $quote = app(CleaningMaterialQuoteService::class)->quote([
        'cleaning_mode' => 'deep',
        'room_size_breakdown' => [
            'bedroom' => ['large' => 2],
        ],
    ]);

    expect($quote['lines'])->toHaveCount(1)
        ->and($quote['lines'][0]['quantity'])->toBe(3.0)
        ->and($quote['lines'][0]['unit'])->toBe($unit->code)
        ->and($quote['lines'][0]['totalPrice'])->toBe(30.0)
        ->and($quote['total'])->toBe(30.0);
});

it('quotes active special services with configured dirtiness and equipment snapshots', function (): void {
    $service = CleaningSpecialService::query()->create([
        'name' => 'Sofa cleaning '.fake()->unique()->numerify('####'),
        'pricing_unit' => 'sofa',
        'base_unit_price' => 100,
        'is_active' => true,
    ]);
    CleaningSpecialServiceDirtinessRule::query()->create([
        'cleaning_special_service_id' => $service->id,
        'dirtiness_level' => 'heavy',
        'price_multiplier' => 1.5,
        'is_active' => true,
    ]);
    $equipment = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Extractor '.fake()->unique()->numerify('####'),
        'is_active' => true,
    ]);
    $service->equipment()->attach($equipment->id);

    $quote = app(CleaningSpecialServiceQuoteService::class)->quote([[
        'specialServiceId' => $service->id,
        'quantity' => 2,
        'dirtinessLevel' => 'heavy',
        'notes' => '  Pet hair  ',
    ]]);

    expect($quote['lines'])->toHaveCount(1)
        ->and($quote['lines'][0]['pricingUnit'])->toBe('sofa')
        ->and($quote['lines'][0]['priceMultiplier'])->toBe(1.5)
        ->and($quote['lines'][0]['totalPrice'])->toBe(300.0)
        ->and($quote['lines'][0]['notes'])->toBe('Pet hair')
        ->and($quote['lines'][0]['equipment'][0]['id'])->toBe($equipment->id)
        ->and($quote['total'])->toBe(300.0);
});

it('rejects duplicate and inactive special service requests', function (): void {
    $service = CleaningSpecialService::query()->create([
        'name' => 'Inactive '.fake()->unique()->numerify('####'),
        'pricing_unit' => 'piece',
        'base_unit_price' => 50,
        'is_active' => false,
    ]);

    expect(fn () => app(CleaningSpecialServiceQuoteService::class)->quote([[
        'specialServiceId' => $service->id,
        'quantity' => 1,
        'dirtinessLevel' => 'light',
    ]]))->toThrow(InvalidArgumentException::class);

    $service->update(['is_active' => true]);
    CleaningSpecialServiceDirtinessRule::query()->create([
        'cleaning_special_service_id' => $service->id,
        'dirtiness_level' => 'light',
        'price_multiplier' => 1,
        'is_active' => true,
    ]);

    expect(fn () => app(CleaningSpecialServiceQuoteService::class)->quote([
        ['specialServiceId' => $service->id, 'quantity' => 1, 'dirtinessLevel' => 'light'],
        ['specialServiceId' => $service->id, 'quantity' => 1, 'dirtinessLevel' => 'light'],
    ]))->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialTypeQuantityRule;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Services\CleaningMaterialQuoteService;

it('quotes only missing materials and keeps legacy all-material selection compatible', function (): void {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Bottle', 'code' => 'missing-test-'.fake()->unique()->numerify('#####'),
        'symbol' => 'b', 'is_active' => true,
    ]);
    $types = [];
    foreach ([10.0, 20.0] as $price) {
        $type = CleaningMaterialType::query()->create([
            'name' => 'Missing material '.fake()->unique()->numerify('#####'),
            'cleaning_material_unit_id' => $unit->id,
            'price_per_unit' => $price,
            'is_active' => true,
        ]);
        CleaningMaterialTypeQuantityRule::query()->create([
            'cleaning_material_type_id' => $type->id,
            'room_type' => 'bedroom', 'room_size' => 'small',
            'cleaning_mode' => 'regular',
            'quantity_per_room' => 1,
            'is_active' => true, 'requires_admin_resolution' => false,
        ]);
        CleaningMaterial::query()->create([
            'name' => 'Material stock '.fake()->unique()->numerify('#####'),
            'cleaning_material_type_id' => $type->id,
            'stock_quantity' => 10,
            'low_stock_threshold' => 1,
            'is_active' => true,
        ]);
        $types[] = $type;
    }

    $details = [
        'cleaning_mode' => 'regular',
        'room_size_breakdown' => ['bedroom' => ['small' => 1]],
    ];
    $service = app(CleaningMaterialQuoteService::class);

    $missingOne = $service->quote($details, [(int) $types[0]->id]);
    expect($missingOne['lines'])->toHaveCount(1)
        ->and($missingOne['lines'][0]['materialTypeId'])->toBe((int) $types[0]->id)
        ->and($missingOne['total'])->toBe(10.0);

    $missingOther = $service->quote($details, [(int) $types[1]->id]);
    expect($missingOther['lines'])->toHaveCount(1)
        ->and($missingOther['total'])->toBe(20.0);

    $allSelected = $service->quote($details, [(int) $types[0]->id, (int) $types[1]->id]);
    expect($allSelected['lines'])->toHaveCount(2)
        ->and($allSelected['total'])->toBe(30.0);

    $nothingMissing = $service->quote($details, []);
    expect($nothingMissing['lines'])->toBeEmpty()
        ->and($nothingMissing['total'])->toBe(0.0);

    // The original requestMaterials:true without a list still quotes all types.
    $legacy = $service->quote($details);
    expect(count($legacy['lines']))->toBeGreaterThanOrEqual(2)
        ->and($legacy['total'])->toBeGreaterThanOrEqual(30.0);
});

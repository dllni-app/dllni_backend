<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Services\CleaningMaterialLowStockPolicy;

it('notifies only when stock crosses from above the threshold to at or below it', function (): void {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Unit',
        'code' => 'agent-b-policy-'.fake()->unique()->numerify('####'),
        'symbol' => 'u',
        'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Policy type '.fake()->unique()->numerify('####'),
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 1,
        'is_active' => true,
    ]);
    $material = CleaningMaterial::query()->create([
        'name' => 'Policy material '.fake()->unique()->numerify('####'),
        'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 3,
        'low_stock_threshold' => 2,
        'is_active' => true,
    ]);
    $policy = app(CleaningMaterialLowStockPolicy::class);

    expect($policy->shouldNotify($material, 3, 2))->toBeTrue()
        ->and($policy->shouldNotify($material, 4, 3))->toBeFalse()
        ->and($policy->shouldNotify($material, 2, 1))->toBeFalse()
        ->and($policy->shouldNotify($material, 1, 0))->toBeFalse();
});

<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialTypeQuantityRule;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Services\CleaningMaterialQuoteService;

it('reserves and bills indivisible cleaning packages as whole units', function (): void {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'عبوة',
        'code' => 'pack',
        'symbol' => 'عبوة',
        'is_active' => true,
    ]);

    $type = CleaningMaterialType::query()->create([
        'name' => 'منظف أرضيات تجريبي',
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 150,
        'is_active' => true,
    ]);

    CleaningMaterialTypeQuantityRule::query()->create([
        'cleaning_material_type_id' => $type->id,
        'room_type' => 'living_room',
        'room_size' => 'medium',
        'cleaning_mode' => 'regular',
        'quantity_per_room' => 0.2,
        'is_active' => true,
        'requires_admin_resolution' => false,
    ]);

    CleaningMaterial::query()->create([
        'name' => 'عبوة منظف أرضيات تجريبية',
        'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 4,
        'low_stock_threshold' => 1,
        'is_active' => true,
    ]);

    $quote = app(CleaningMaterialQuoteService::class)->quote([
        'cleaning_mode' => 'regular',
        'room_size_breakdown' => ['living_room' => ['medium' => 1]],
    ], [(int) $type->id]);

    expect($quote['lines'])->toHaveCount(1)
        ->and($quote['lines'][0]['unit'])->toBe('pack')
        ->and($quote['lines'][0]['quantity'])->toBe(1.0)
        ->and($quote['total'])->toBe(150.0);
});

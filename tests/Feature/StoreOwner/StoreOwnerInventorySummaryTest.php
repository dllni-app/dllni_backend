<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

it('returns inventory summary for the authenticated owner default store', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'price' => 100,
        'discounted_price' => 80,
        'stock_quantity' => 10,
        'low_stock_threshold' => 10,
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'price' => 50,
        'discounted_price' => null,
        'stock_quantity' => 3,
        'low_stock_threshold' => 2,
        'is_available' => true,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/store-owner/inventory/summary');

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.productSkus', 2)
        ->assertJsonPath('data.lowStockCount', 1);

    expect((float) $response->json('data.inventoryValue'))->toBe(950.0);
});

it('returns 403 when authenticated owner has no store', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/store-owner/inventory/summary');

    $response->assertForbidden()
        ->assertJsonPath('message', 'No store found for the authenticated store owner.');
});

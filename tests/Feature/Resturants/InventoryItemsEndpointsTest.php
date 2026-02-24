<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns inventory summary for restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Olive Oil',
        'unit' => 'liter',
        'quantity' => 10,
        'minimum_limit' => 5,
        'unit_cost' => 5.5,
    ]);

    $response = $this->getJson('/api/v1/restaurant/inventory-summary?restaurantId='.$restaurant->id);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'totalItems',
            'lowStockCount',
            'expiringItemsCount',
            'totalValue',
        ],
    ]);
    expect($response->json('data.totalItems'))->toBe(1);
});

it('returns inventory alerts for restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Low Stock Item',
        'unit' => 'kg',
        'quantity' => 2,
        'minimum_limit' => 5,
        'unit_cost' => 10,
    ]);

    $response = $this->getJson('/api/v1/restaurant/inventory-alerts?restaurantId='.$restaurant->id);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('creates inventory item', function () {
    $restaurant = Restaurant::factory()->create();

    $response = $this->postJson('/api/v1/inventory-items', [
        'restaurantId' => $restaurant->id,
        'name' => 'Basmati Rice',
        'unit' => 'kg',
        'quantity' => 25,
        'minimumLimit' => 5,
        'unitCost' => 3.5,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.name', 'Basmati Rice');
    expect($response->json('data.quantity'))->toBeIn([25, 25.0]);
    $this->assertDatabaseHas('inventory_items', [
        'restaurant_id' => $restaurant->id,
        'name' => 'Basmati Rice',
    ]);
});

it('lists inventory items with filter', function () {
    $restaurant = Restaurant::factory()->create();
    InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Item A',
        'unit' => 'piece',
        'quantity' => 10,
        'minimum_limit' => 5,
        'unit_cost' => 1,
    ]);

    $response = $this->getJson('/api/v1/inventory-items?filter[restaurantId]='.$restaurant->id);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

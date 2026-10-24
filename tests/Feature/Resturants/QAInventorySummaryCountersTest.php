<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Restaurant;

it('recounts low stock in summary after creating an item', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    $this->getJson('/api/v1/restaurant/inventory-summary')
        ->assertOk()
        ->assertJsonPath('data.lowStockCount', 0);

    $this->postJson('/api/v1/inventory-items', [
        'name' => 'QA Low Stock Fixture',
        'unit' => 'kg',
        'quantity' => 1,
        'minimumLimit' => 2,
        'unitCost' => 0,
    ])->assertCreated();

    $this->getJson('/api/v1/restaurant/inventory-summary')
        ->assertOk()
        ->assertJsonPath('data.totalItems', 1)
        ->assertJsonPath('data.lowStockCount', 1);

    $this->getJson('/api/v1/inventory-items?filter[status]=low')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows the zero-stock count expected by the exhausted-items UI card', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'QA Exhausted Fixture',
        'unit' => 'kg',
        'quantity' => 0,
        'minimum_limit' => 2,
        'unit_cost' => 0,
    ]);

    $this->getJson('/api/v1/restaurant/inventory-summary')
        ->assertOk()
        ->assertJsonPath('data.expiringItemsCount', 1);
});

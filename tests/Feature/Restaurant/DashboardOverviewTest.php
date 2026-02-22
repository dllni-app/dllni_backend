<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns dashboard overview with kpis', function () {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id);

    $response->assertOk();
    $response->assertJsonStructure([
        'kpis' => [
            'todayOrders',
            'ordersByStatus',
            'activeRestaurants',
            'openDisputes',
            'ordersPendingPickup',
            'ordersReadyForPickup',
            'lowStockAlertsCount',
        ],
    ]);
});

it('includes today orders count in dashboard overview', function () {
    $restaurant = Restaurant::factory()->create();
    Order::factory()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id);

    $response->assertOk();
    expect($response->json('kpis.todayOrders'))->toBe(2);
});

it('includes active restaurants count in dashboard overview', function () {
    $activeRestaurant = Restaurant::factory()->create(['is_active' => true]);
    $inactiveRestaurant = Restaurant::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$activeRestaurant->id);

    $response->assertOk();
    expect($response->json('kpis.activeRestaurants'))->toBe(1);

    $responseInactive = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$inactiveRestaurant->id);

    $responseInactive->assertOk();
    expect($responseInactive->json('kpis.activeRestaurants'))->toBe(0);
});

it('includes low stock alerts count in dashboard overview', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    Product::factory()->lowStock()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
    ]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id);

    $response->assertOk();
    expect($response->json('kpis.lowStockAlertsCount'))->toBe(2);
});

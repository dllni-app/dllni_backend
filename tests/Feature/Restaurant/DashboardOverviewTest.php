<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

function actingAsRestaurantDashboardOwner(array $restaurantAttributes = []): Restaurant
{
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $restaurant = Restaurant::factory()->create([
        ...$restaurantAttributes,
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);

    return $restaurant;
}

it('returns dashboard overview with kpis', function () {
    $restaurant = actingAsRestaurantDashboardOwner(['is_active' => true]);

    $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id)
        ->assertOk()
        ->assertJsonStructure([
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
    $restaurant = actingAsRestaurantDashboardOwner();

    Order::factory()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id);

    $response->assertOk();
    expect($response->json('kpis.todayOrders'))->toBe(2);
});

it('reflects the owned restaurant active state in dashboard overview', function () {
    $activeRestaurant = actingAsRestaurantDashboardOwner(['is_active' => true]);

    $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$activeRestaurant->id)
        ->assertOk()
        ->assertJsonPath('kpis.activeRestaurants', 1);

    $inactiveRestaurant = actingAsRestaurantDashboardOwner(['is_active' => false]);

    $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$inactiveRestaurant->id)
        ->assertOk()
        ->assertJsonPath('kpis.activeRestaurants', 0);
});

it('includes low stock alerts count in dashboard overview', function () {
    $restaurant = actingAsRestaurantDashboardOwner();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    Product::factory()->lowStock()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
    ]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id);

    $response->assertOk();
    expect($response->json('kpis.lowStockAlertsCount'))->toBe(2);
});

it('does not expose another restaurant dashboard through the legacy restaurantId parameter', function () {
    Sanctum::actingAs(User::factory()->create());
    $restaurant = Restaurant::factory()->create();

    $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id)
        ->assertForbidden();
});

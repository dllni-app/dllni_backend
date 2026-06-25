<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'phone' => '+963933100001',
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);

    Sanctum::actingAs($this->owner);
});

it('scopes restaurant owner orders index to the authenticated restaurant', function () {
    $ownPendingOrder = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Pending->value,
        'created_at' => now(),
    ]);

    $ownCompletedOrder = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Completed->value,
        'created_at' => now(),
    ]);

    $otherRestaurant = Restaurant::factory()->create();
    $otherOrder = Order::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'status' => OrderStatus::Pending->value,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/restaurant-owner/orders?perPage=50');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($ownPendingOrder->id, $ownCompletedOrder->id)
        ->not->toContain($otherOrder->id);
});

it('keeps the legacy orders endpoint scoped for restaurant sellers', function () {
    $ownOrder = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);

    $otherOrder = Order::factory()->create([
        'restaurant_id' => Restaurant::factory()->create()->id,
        'status' => OrderStatus::Pending->value,
    ]);

    $response = $this->getJson('/api/v1/orders?perPage=50');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($ownOrder->id)
        ->not->toContain($otherOrder->id);
});

it('applies restaurant owner status filter after merchant scoping', function () {
    $ownPendingOrder = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);

    $ownCompletedOrder = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Completed->value,
    ]);

    $otherPendingOrder = Order::factory()->create([
        'restaurant_id' => Restaurant::factory()->create()->id,
        'status' => OrderStatus::Pending->value,
    ]);

    $response = $this->getJson('/api/v1/restaurant-owner/orders?filter[status]=pending&perPage=50');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($ownPendingOrder->id)
        ->not->toContain($ownCompletedOrder->id, $otherPendingOrder->id);
});

it('forbids generic order detail access to another restaurant for restaurant sellers', function () {
    $otherOrder = Order::factory()->create([
        'restaurant_id' => Restaurant::factory()->create()->id,
    ]);

    $this->getJson("/api/v1/orders/{$otherOrder->id}")
        ->assertForbidden();
});

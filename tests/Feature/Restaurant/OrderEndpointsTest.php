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
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);

    Sanctum::actingAs($this->owner);
});

it('lists only orders for the authenticated restaurant', function () {
    Order::factory()->count(3)->create([
        'restaurant_id' => $this->restaurant->id,
    ]);
    Order::factory()->create();

    $response = $this->getJson('/api/v1/orders');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('does not expose generic order creation', function () {
    $this->postJson('/api/v1/orders', [
        'status' => OrderStatus::Pending->value,
    ])->assertMethodNotAllowed();
});

it('shows an owned order', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'order_number' => 'ORD-SHOW-1234',
    ]);

    $response = $this->getJson("/api/v1/orders/{$order->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($order->id)
        ->and($response->json('data.orderNumber'))->toBe('ORD-SHOW-1234');
});

it('forbids reading another restaurant order', function () {
    $order = Order::factory()->create();

    $this->getJson("/api/v1/orders/{$order->id}")
        ->assertForbidden();
});

it('does not expose generic order update', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);

    $this->putJson("/api/v1/orders/{$order->id}", [
        'status' => OrderStatus::Accepted->value,
    ])->assertMethodNotAllowed();
});

it('does not expose generic order deletion', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
    ]);

    $this->deleteJson("/api/v1/orders/{$order->id}")
        ->assertMethodNotAllowed();

    $this->assertDatabaseHas('orders', ['id' => $order->id]);
});

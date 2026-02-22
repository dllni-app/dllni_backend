<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists orders', function () {
    Order::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/orders');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates an order', function () {
    $customer = User::factory()->create(['email' => 'customer@example.com']);
    $restaurant = Restaurant::factory()->create();

    $payload = [
        'userId' => $customer->id,
        'restaurantId' => $restaurant->id,
        'orderNumber' => 'ORD-'.mb_strtoupper(Str::random(8)).'-'.fake()->unique()->randomNumber(4),
        'status' => OrderStatus::Pending->value,
        'orderType' => 'pickup',
        'pickupMode' => 'immediate_pickup',
        'subtotal' => 50,
        'totalAmount' => 55,
    ];

    $response = $this->postJson('/api/v1/orders', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('orders', [
        'user_id' => $customer->id,
        'restaurant_id' => $restaurant->id,
    ]);
});

it('shows an order', function () {
    $order = Order::factory()->create(['order_number' => 'ORD-SHOW-1234']);

    $response = $this->getJson("/api/v1/orders/{$order->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($order->id);
    expect($response->json('data.orderNumber'))->toBe('ORD-SHOW-1234');
});

it('updates an order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending->value]);

    $response = $this->putJson("/api/v1/orders/{$order->id}", [
        'userId' => $order->user_id,
        'restaurantId' => $order->restaurant_id,
        'orderNumber' => $order->order_number,
        'status' => OrderStatus::Accepted->value,
        'orderType' => $order->order_type->value,
        'pickupMode' => $order->pickup_mode->value,
        'subtotal' => (float) $order->subtotal,
        'totalAmount' => (float) $order->total_amount,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => OrderStatus::Accepted->value,
    ]);
});

it('deletes an order', function () {
    $order = Order::factory()->create();

    $response = $this->deleteJson("/api/v1/orders/{$order->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('orders', ['id' => $order->id]);
});

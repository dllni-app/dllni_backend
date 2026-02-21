<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists orders', function (): void {
    SmOrderFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-orders?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates an order', function (): void {
    $customer = User::factory()->create();
    $store = SmStoreFactory::new()->create();

    $payload = [
        'customerId' => $customer->id,
        'storeId' => $store->id,
        'orderNumber' => 'ORD-1001',
        'status' => 'pending',
        'pickupMode' => 'immediate_pickup',
        'subtotal' => 100,
        'discountAmount' => 0,
        'serviceFee' => 5,
        'totalAmount' => 105,
    ];

    $response = $this->postJson('/api/v1/sm-orders', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_orders', ['order_number' => 'ORD-1001']);
});

it('updates an order', function (): void {
    $order = SmOrderFactory::new()->create(['status' => 'pending']);

    $payload = [
        'status' => 'accepted',
    ];

    $response = $this->putJson("/api/v1/sm-orders/{$order->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_orders', ['id' => $order->id, 'status' => 'accepted']);
});

it('deletes an order', function (): void {
    $order = SmOrderFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-orders/{$order->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_orders', ['id' => $order->id]);
});

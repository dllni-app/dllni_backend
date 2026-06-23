<?php

declare(strict_types=1);

use App\Models\User;
use App\Enums\UserModuleType;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($this->owner);

    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->owner->id,
    ]);
});

it('lists orders', function (): void {
    SmOrderFactory::new()->count(3)->create([
        'store_id' => $this->store->id,
    ]);
    SmOrderFactory::new()->count(2)->create();

    $response = $this->getJson('/api/v1/sm-orders?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('returns item stock availability on listed orders', function (): void {
    $availableProduct = SmProductFactory::new()->create([
        'store_id' => $this->store->id,
        'is_available' => true,
        'stock_quantity' => 10,
    ]);
    $outOfStockProduct = SmProductFactory::new()->create([
        'store_id' => $this->store->id,
        'is_available' => true,
        'stock_quantity' => 1,
    ]);
    $unavailableProduct = SmProductFactory::new()->create([
        'store_id' => $this->store->id,
        'is_available' => false,
        'stock_quantity' => 10,
    ]);

    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $order->id,
        'product_id' => $availableProduct->id,
        'quantity' => 2,
    ]);
    SmOrderItemFactory::new()->create([
        'order_id' => $order->id,
        'product_id' => $outOfStockProduct->id,
        'quantity' => 2,
    ]);
    SmOrderItemFactory::new()->create([
        'order_id' => $order->id,
        'product_id' => $unavailableProduct->id,
        'quantity' => 2,
    ]);

    $response = $this->getJson('/api/v1/sm-orders?perPage=10');

    $response->assertOk()
        ->assertJsonPath('data.0.items.0.isAvailableInStock', true)
        ->assertJsonPath('data.0.items.1.isAvailableInStock', false)
        ->assertJsonPath('data.0.items.2.isAvailableInStock', false);
});

it('creates an order', function (): void {
    $customer = User::factory()->create();
    $otherStore = SmStoreFactory::new()->create();

    $payload = [
        'customerId' => $customer->id,
        'storeId' => $otherStore->id,
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
    $this->assertDatabaseHas('sm_orders', [
        'order_number' => 'ORD-1001',
        'store_id' => $this->store->id,
    ]);
});

it('updates an order', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => 'pending',
    ]);

    $payload = [
        'status' => 'accepted',
    ];

    $response = $this->putJson("/api/v1/sm-orders/{$order->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_orders', ['id' => $order->id, 'status' => 'accepted']);
});

it('deletes an order', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
    ]);

    $response = $this->deleteJson("/api/v1/sm-orders/{$order->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_orders', ['id' => $order->id]);
});

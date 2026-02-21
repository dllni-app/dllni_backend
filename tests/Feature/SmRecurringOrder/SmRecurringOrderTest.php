<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmRecurringOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists recurring orders', function (): void {
    SmRecurringOrderFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-recurring-orders?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a recurring order', function (): void {
    $user = User::factory()->create();
    $store = SmStoreFactory::new()->create();

    $payload = [
        'userId' => $user->id,
        'storeId' => $store->id,
        'status' => 'active',
        'frequency' => 'weekly',
    ];

    $response = $this->postJson('/api/v1/sm-recurring-orders', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_recurring_orders', ['user_id' => $user->id, 'store_id' => $store->id]);
});

it('updates a recurring order', function (): void {
    $order = SmRecurringOrderFactory::new()->create(['status' => 'active']);

    $payload = [
        'status' => 'paused',
    ];

    $response = $this->putJson("/api/v1/sm-recurring-orders/{$order->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_recurring_orders', ['id' => $order->id, 'status' => 'paused']);
});

it('deletes a recurring order', function (): void {
    $order = SmRecurringOrderFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-recurring-orders/{$order->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_recurring_orders', ['id' => $order->id]);
});

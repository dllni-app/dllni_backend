<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCartFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists carts', function (): void {
    SmCartFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-carts?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a cart', function (): void {
    $user = User::factory()->create();
    $store = SmStoreFactory::new()->create();

    $payload = [
        'userId' => $user->id,
        'storeId' => $store->id,
    ];

    $response = $this->postJson('/api/v1/sm-carts', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_carts', ['user_id' => $user->id, 'store_id' => $store->id]);
});

it('updates a cart', function (): void {
    $cart = SmCartFactory::new()->create();
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
    ];

    $response = $this->putJson("/api/v1/sm-carts/{$cart->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_carts', ['id' => $cart->id, 'store_id' => $store->id]);
});

it('deletes a cart', function (): void {
    $cart = SmCartFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-carts/{$cart->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_carts', ['id' => $cart->id]);
});

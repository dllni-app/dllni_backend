<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCartFactory;
use Database\Factories\SmCartItemFactory;
use Database\Factories\SmProductFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists cart items', function (): void {
    SmCartItemFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-cart-items?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a cart item', function (): void {
    $cart = SmCartFactory::new()->create();
    $product = SmProductFactory::new()->create();

    $payload = [
        'cartId' => $cart->id,
        'productId' => $product->id,
        'quantity' => 2,
        'unitPrice' => 10.5,
    ];

    $response = $this->postJson('/api/v1/sm-cart-items', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_cart_items', ['cart_id' => $cart->id, 'product_id' => $product->id]);
});

it('updates a cart item', function (): void {
    $item = SmCartItemFactory::new()->create(['quantity' => 1]);

    $payload = [
        'quantity' => 3,
    ];

    $response = $this->putJson("/api/v1/sm-cart-items/{$item->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_cart_items', ['id' => $item->id, 'quantity' => 3]);
});

it('deletes a cart item', function (): void {
    $item = SmCartItemFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-cart-items/{$item->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_cart_items', ['id' => $item->id]);
});

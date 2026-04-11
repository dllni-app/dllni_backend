<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('requires authentication to fetch restaurant cart', function (): void {
    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertUnauthorized();
});

it('returns empty cart payload when user has no restaurant cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertOk()->assertJsonPath('data.id', null);
    expect($response->json('data.items'))->toBeArray()->toBeEmpty();
});

it('returns cart items for the authenticated user after adding to cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 25,
        'discounted_price' => null,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 3,
    ])->assertCreated();

    $response = $this->getJson('/api/v1/user/restaurants/cart');

    $response->assertOk()
        ->assertJsonPath('data.merchant.id', $restaurant->id)
        ->assertJsonPath('data.items.0.productId', $product->id)
        ->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('data.items.0.name', $product->name);

    expect($response->json('data.id'))->toBeInt();
});

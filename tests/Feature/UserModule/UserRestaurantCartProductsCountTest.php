<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('returns total products count in authenticated user restaurant carts', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurantA = Restaurant::factory()->create(['is_active' => true]);
    $restaurantB = Restaurant::factory()->create(['is_active' => true]);

    $productA = Product::factory()->create([
        'restaurant_id' => $restaurantA->id,
        'is_available' => true,
    ]);

    $productB = Product::factory()->create([
        'restaurant_id' => $restaurantB->id,
        'is_available' => true,
    ]);

    $cartA = Cart::query()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurantA->id,
    ]);

    $cartB = Cart::query()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurantB->id,
    ]);

    CartItem::query()->create([
        'cart_id' => $cartA->id,
        'product_id' => $productA->id,
        'substitute_product_id' => null,
        'quantity' => 2,
        'unit_price' => 10,
        'total_price' => 20,
        'special_instructions' => null,
    ]);

    CartItem::query()->create([
        'cart_id' => $cartB->id,
        'product_id' => $productB->id,
        'substitute_product_id' => null,
        'quantity' => 3,
        'unit_price' => 12,
        'total_price' => 36,
        'special_instructions' => null,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/cart/products-count');

    $response->assertSuccessful()->assertJson([
        'productsCount' => 5,
    ]);
});

it('requires authentication to get restaurant cart products count', function (): void {
    $this->getJson('/api/v1/user/restaurants/cart/products-count')
        ->assertUnauthorized();
});

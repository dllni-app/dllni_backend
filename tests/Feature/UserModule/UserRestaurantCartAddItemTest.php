<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('adds a product to restaurant cart for authenticated user', function (): void {
    // Arrange
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 30,
        'discounted_price' => null,
    ]);

    $group = ModifierGroup::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Sauces',
        'is_required' => false,
        'min_selections' => 0,
        'max_selections' => 2,
    ]);
    $group->products()->attach($product->id);

    $modifier = Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Extra sauce',
        'price' => 2,
        'sort_order' => 1,
    ]);

    // Act
    $response = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
        'modifierIds' => [$modifier->id],
        'specialInstructions' => 'No onions',
    ]);

    // Assert
    $response->assertCreated()->assertJsonStructure([
        'message',
        'cartId',
        'itemId',
    ]);

    $this->assertDatabaseHas('carts', [
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
    ]);

    $this->assertDatabaseHas('cart_items', [
        'id' => $response->json('itemId'),
        'quantity' => 2,
        'special_instructions' => 'No onions',
    ]);

    $this->assertDatabaseHas('cart_item_modifier', [
        'cart_item_id' => $response->json('itemId'),
        'modifier_id' => $modifier->id,
        'price' => 2,
    ]);
});

it('keeps a single active restaurant cart per user when merchant changes', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstRestaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);
    $secondRestaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $firstProduct = Product::factory()->create([
        'restaurant_id' => $firstRestaurant->id,
        'is_available' => true,
        'price' => 21,
    ]);
    $secondProduct = Product::factory()->create([
        'restaurant_id' => $secondRestaurant->id,
        'is_available' => true,
        'price' => 33,
    ]);

    $firstAddResponse = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $firstProduct->id,
        'quantity' => 1,
    ])->assertCreated();

    $firstCartId = (int) $firstAddResponse->json('cartId');

    $secondAddResponse = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $secondProduct->id,
        'quantity' => 2,
    ])->assertCreated();

    $secondAddResponse->assertJsonPath('cartId', $firstCartId);

    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseHas('carts', [
        'id' => $firstCartId,
        'user_id' => $user->id,
        'restaurant_id' => $secondRestaurant->id,
    ]);
    $this->assertDatabaseMissing('cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $firstProduct->id,
    ]);
    $this->assertDatabaseHas('cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $secondProduct->id,
        'quantity' => 2,
    ]);
});

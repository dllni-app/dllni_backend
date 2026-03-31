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

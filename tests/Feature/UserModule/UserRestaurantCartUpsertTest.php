<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

function createRestaurantProductWithModifier(): array
{
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

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

    return [$restaurant, $product, $modifier];
}

it('updates the matching restaurant cart item instead of creating a duplicate', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [, $product] = createRestaurantProductWithModifier();

    $first = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 7,
    ])->assertOk()->assertJson([
        'itemId' => $first->json('itemId'),
        'quantity' => 7,
        'operation' => 'updated',
    ]);

    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseHas('cart_items', [
        'id' => $first->json('itemId'),
        'product_id' => $product->id,
        'quantity' => 7,
    ]);
});

it('keeps separate cart lines for the same product with different modifiers', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [, $product, $modifier] = createRestaurantProductWithModifier();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$modifier->id],
    ])->assertCreated();

    $this->assertDatabaseCount('cart_items', 2);
});

it('preserves modifiers when updating quantity only', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [, $product, $modifier] = createRestaurantProductWithModifier();

    $response = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
        'modifierIds' => [$modifier->id],
    ])->assertCreated();

    $this->patchJson('/api/v1/user/restaurants/cart/items/'.$response->json('itemId'), [
        'quantity' => 4,
    ])->assertOk();

    $this->assertDatabaseHas('cart_items', [
        'id' => $response->json('itemId'),
        'quantity' => 4,
    ]);
    $this->assertDatabaseHas('cart_item_modifier', [
        'cart_item_id' => $response->json('itemId'),
        'modifier_id' => $modifier->id,
    ]);
});

it('returns product cartQuantity in product details', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [, $product] = createRestaurantProductWithModifier();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 3,
    ])->assertCreated();

    $this->getJson('/api/v1/user/products/'.$product->id)
        ->assertOk()
        ->assertJsonPath('product.cartQuantity', 3);
});

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
        'quantity',
        'cartProductsCount',
        'operation',
    ])->assertJsonPath('operation', 'created')
        ->assertJsonPath('quantity', 2)
        ->assertJsonPath('cartProductsCount', 2);

    $this->assertDatabaseHas('carts', [
        'user_id' => $user->id,
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

it('preserves items from all restaurants in the same cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstRestaurant = Restaurant::factory()->create(['is_active' => true]);
    $secondRestaurant = Restaurant::factory()->create(['is_active' => true]);

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

    // Same cart is reused
    expect($secondAddResponse->json('cartId'))->toBe($firstCartId);

    // Only one cart exists
    $this->assertDatabaseCount('carts', 1);

    // Both items are present
    $this->assertDatabaseHas('cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $firstProduct->id,
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $secondProduct->id,
        'quantity' => 2,
    ]);
});

it('increments an existing matching restaurant cart item instead of creating a duplicate', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 15,
    ]);

    $firstResponse = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $secondResponse = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertOk();

    expect($secondResponse->json('itemId'))->toBe($firstResponse->json('itemId'));

    $secondResponse->assertJsonPath('operation', 'updated')
        ->assertJsonPath('quantity', 3)
        ->assertJsonPath('cartProductsCount', 3);

    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseHas('cart_items', [
        'id' => $firstResponse->json('itemId'),
        'product_id' => $product->id,
        'quantity' => 3,
        'total_price' => 45,
    ]);
});

it('sets an existing matching restaurant cart item quantity when quantityMode is set', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 15,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 5,
    ])->assertCreated();

    $response = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
        'quantityMode' => 'set',
    ])->assertOk();

    $response->assertJsonPath('operation', 'updated')
        ->assertJsonPath('quantity', 2)
        ->assertJsonPath('cartProductsCount', 2);

    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseHas('cart_items', [
        'product_id' => $product->id,
        'quantity' => 2,
        'total_price' => 30,
    ]);
});

it('keeps separate lines for the same product when modifiers differ', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 20,
    ]);

    $group = ModifierGroup::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Extras',
        'is_required' => false,
        'min_selections' => 0,
        'max_selections' => 2,
    ]);
    $group->products()->attach($product->id);

    $firstModifier = Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Cheese',
        'price' => 3,
        'sort_order' => 1,
    ]);
    $secondModifier = Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Mushroom',
        'price' => 4,
        'sort_order' => 2,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$firstModifier->id],
    ])->assertCreated();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$secondModifier->id],
    ])->assertCreated();

    $this->assertDatabaseCount('cart_items', 2);
});

it('preserves modifiers when patching only restaurant cart item quantity', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 30,
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

    $addResponse = $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$modifier->id],
    ])->assertCreated();

    $this->patchJson('/api/v1/user/restaurants/cart/items/'.$addResponse->json('itemId'), [
        'quantity' => 7,
    ])->assertOk();

    $this->assertDatabaseHas('cart_items', [
        'id' => $addResponse->json('itemId'),
        'quantity' => 7,
        'total_price' => 224,
    ]);

    $this->assertDatabaseHas('cart_item_modifier', [
        'cart_item_id' => $addResponse->json('itemId'),
        'modifier_id' => $modifier->id,
        'price' => 2,
    ]);
});

it('returns cart quantity on restaurant product details', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 10,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 4,
    ])->assertCreated();

    $this->getJson('/api/v1/user/products/'.$product->id)
        ->assertOk()
        ->assertJsonPath('product.id', $product->id)
        ->assertJsonPath('product.cartQuantity', 4);
});

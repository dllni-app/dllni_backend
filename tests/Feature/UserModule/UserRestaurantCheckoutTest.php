<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;

it('creates a single order from cart and clears the cart', function (): void {
    // Arrange
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'price' => 30,
        'discounted_price' => null,
    ]);

    $group = ModifierGroup::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Add-ons',
        'is_required' => false,
        'min_selections' => 0,
        'max_selections' => 3,
    ]);
    $group->products()->attach($product->id);

    $modifier = Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Cheese',
        'price' => 2,
        'sort_order' => 1,
    ]);

    $promo = PromoCode::create([
        'restaurant_id' => $restaurant->id,
        'code' => 'SAVE10',
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'min_order_amount' => null,
        'usage_limit' => null,
        'usage_count' => 0,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
        'modifierIds' => [$modifier->id],
    ])->assertCreated();

    $cart = Cart::query()->where('user_id', $user->id)->first();
    expect($cart)->not->toBeNull();

    // Act
    $response = $this->postJson('/api/v1/user/restaurants/checkout', [
        'orderType' => 'pickup',
        'promoCode' => $promo->code,
        'specialInstructions' => 'Ring the bell',
    ]);

    // Assert
    $response->assertCreated()->assertJsonStructure([
        'message',
        'order' => ['id', 'restaurantId', 'orderNumber', 'status', 'subtotal', 'discountAmount', 'totalAmount'],
    ]);

    $orderId = $response->json('order.id');
    $this->assertDatabaseHas('orders', [
        'id' => $orderId,
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'promo_code_id' => $promo->id,
    ]);

    $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
    $this->assertDatabaseCount('orders', 1);
});

it('creates ONE order even when cart has items from multiple restaurants', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurantA = Restaurant::factory()->create(['is_active' => true]);
    $restaurantB = Restaurant::factory()->create(['is_active' => true]);

    $productA = Product::factory()->create([
        'restaurant_id' => $restaurantA->id,
        'is_available' => true,
        'price' => 20,
    ]);
    $productB = Product::factory()->create([
        'restaurant_id' => $restaurantB->id,
        'is_available' => true,
        'price' => 15,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $productA->id,
        'quantity' => 1,
    ])->assertCreated();

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $productB->id,
        'quantity' => 2,
    ])->assertCreated();

    // Act
    $response = $this->postJson('/api/v1/user/restaurants/checkout', [
        'orderType' => 'pickup',
    ]);

    // Assert: single order
    $response->assertCreated()->assertJsonStructure([
        'message',
        'order' => ['id', 'orderNumber', 'status', 'subtotal', 'totalAmount'],
    ]);

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);

    // restaurant_id is null because items span multiple merchants
    $orderId = $response->json('order.id');
    $this->assertDatabaseHas('orders', [
        'id' => $orderId,
        'user_id' => $user->id,
        'restaurant_id' => null,
    ]);
});

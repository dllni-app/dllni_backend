<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('requires authentication', function (): void {
    $this->postJson('/api/v1/user/restaurants/home/latest-ordered-products/reorder')
        ->assertUnauthorized();
});

it('reorders latest order products into cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

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

    Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'created_at' => now()->subDay(),
    ]);

    $latestOrder = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'created_at' => now(),
    ]);

    $latestOrderItem = OrderItem::create([
        'order_id' => $latestOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 32,
        'total_price' => 64,
        'special_instructions' => 'Less salt',
    ]);

    DB::table('order_item_modifier')->insert([
        'order_item_id' => $latestOrderItem->id,
        'modifier_id' => $modifier->id,
        'price' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/user/restaurants/home/latest-ordered-products/reorder');

    $response->assertCreated()
        ->assertJsonPath('message', 'Latest order products added to cart.')
        ->assertJsonPath('itemsAdded', 1);

    $itemId = $response->json('itemIds.0');

    $this->assertDatabaseHas('carts', [
        'id' => $response->json('cartId'),
        'user_id' => $user->id,
    ]);

    $this->assertDatabaseHas('cart_items', [
        'id' => $itemId,
        'product_id' => $product->id,
        'quantity' => 2,
        'special_instructions' => 'Less salt',
    ]);

    $this->assertDatabaseHas('cart_item_modifier', [
        'cart_item_id' => $itemId,
        'modifier_id' => $modifier->id,
        'price' => 2,
    ]);
});

it('returns validation error when no previous order exists', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/user/restaurants/home/latest-ordered-products/reorder')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['order']);
});

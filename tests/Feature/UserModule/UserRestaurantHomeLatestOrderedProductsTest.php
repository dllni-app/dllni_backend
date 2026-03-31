<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('requires authentication', function (): void {
    $response = $this->getJson('/api/v1/user/restaurants/home/latest-ordered-products');

    $response->assertUnauthorized();
});

it('returns distinct latest ordered products for the authenticated user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Hamburghini',
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Classic Burger Meal',
        'price' => 38,
        'is_available' => true,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'created_at' => now()->subHour(),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 35,
        'total_price' => 35,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/latest-ordered-products');

    $response->assertOk();
    $response->assertJsonPath('latestOrderedProducts.0.productId', $product->id);
    $response->assertJsonPath('latestOrderedProducts.0.productName', 'Classic Burger Meal');
    $response->assertJsonPath('latestOrderedProducts.0.restaurantName', 'Hamburghini');
    $response->assertJsonPath('latestOrderedProducts.0.displayPrice', 38);
    $response->assertJsonPath('latestOrderedProducts.0.lastOrderedLineUnitPrice', 35);
    $response->assertJsonPath('latestOrderedProducts.0.lastOrderId', $order->id);
    expect($response->json('latestOrderedProducts.0.currency'))->toBeString()->not->toBeEmpty();
});

it('deduplicates by product using the most recent order line', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'price' => 10,
        'is_available' => true,
    ]);

    $olderOrder = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'created_at' => now()->subDays(2),
    ]);
    OrderItem::create([
        'order_id' => $olderOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 8,
        'total_price' => 8,
    ]);

    $newerOrder = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'created_at' => now()->subDay(),
    ]);
    OrderItem::create([
        'order_id' => $newerOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 9,
        'total_price' => 9,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/latest-ordered-products');

    $response->assertOk();
    expect($response->json('latestOrderedProducts'))->toHaveCount(1);
    $response->assertJsonPath('latestOrderedProducts.0.lastOrderId', $newerOrder->id);
    $response->assertJsonPath('latestOrderedProducts.0.lastOrderedLineUnitPrice', 9);
});

it('excludes cancelled orders', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Cancelled,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 10,
        'total_price' => 10,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/latest-ordered-products');

    $response->assertOk();
    expect($response->json('latestOrderedProducts'))->toBeArray()->toBeEmpty();
});

it('excludes unavailable products or inactive restaurants', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $inactiveRestaurant = Restaurant::factory()->inactive()->create();
    $unavailableProduct = Product::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'is_available' => true,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $inactiveRestaurant->id,
        'status' => OrderStatus::Completed,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $unavailableProduct->id,
        'quantity' => 1,
        'unit_price' => 10,
        'total_price' => 10,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/latest-ordered-products');

    $response->assertOk();
    expect($response->json('latestOrderedProducts'))->toBeArray()->toBeEmpty();
});

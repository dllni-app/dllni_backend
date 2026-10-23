<?php

declare(strict_types=1);

use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\OrderService;

it('consumes linked ingredient quantity when a restaurant order is accepted', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $ingredient = InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'QA linked ingredient',
        'unit' => 'kg',
        'quantity' => 10,
        'minimum_limit' => 2,
        'unit_cost' => 1,
    ]);
    $ingredient->products()->attach($product->id, ['quantity_used' => 0.5]);

    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);
    $order->orderItems()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    app(OrderService::class)->accept($order, null, null, null);

    expect($order->fresh()->status)->toBe(OrderStatus::Accepted);
    expect((float) $ingredient->fresh()->quantity)->toBe(9.0);
});

it('consumes linked ingredients by the end of the restaurant pickup lifecycle', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => \App\Enums\UserModuleType::RestaurantSeller->value]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $ingredient = InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'QA pickup ingredient',
        'unit' => 'kg',
        'quantity' => 10,
        'minimum_limit' => 2,
        'unit_cost' => 1,
    ]);
    $ingredient->products()->attach($product->id, ['quantity_used' => 0.5]);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Pending->value,
        'order_type' => \Modules\Resturants\Enums\OrderType::Pickup->value,
    ]);
    $order->orderItems()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($restaurant->user);
    foreach (['accepted', 'preparing', 'ready_for_pickup', 'completed'] as $status) {
        $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/status", [
            'status' => $status,
        ])->assertOk();
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
    expect((float) $ingredient->fresh()->quantity)->toBe(9.0);
});

it('restores exactly the consumed quantity when a restaurant order is cancelled', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $ingredient = InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'QA restorable ingredient',
        'unit' => 'kg',
        'quantity' => 10,
        'minimum_limit' => 2,
        'unit_cost' => 1,
    ]);
    $ingredient->products()->attach($product->id, ['quantity_used' => 0.5]);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);
    $order->orderItems()->create([
        'product_id' => $product->id, 'quantity' => 2,
        'unit_price' => 100, 'total_price' => 200,
    ]);

    app(OrderService::class)->accept($order, null, null, null);
    expect((float) $ingredient->fresh()->quantity)->toBe(9.0);

    $order->fresh()->update(['status' => OrderStatus::Cancelled]);
    expect((float) $ingredient->fresh()->quantity)->toBe(10.0);
    app(\Modules\Resturants\Services\RestaurantInventoryConsumptionService::class)->restore($order);
    expect((float) $ingredient->fresh()->quantity)->toBe(10.0);
});

it('rejects order acceptance when linked inventory is insufficient without partial deductions', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $ingredient = InventoryItem::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'QA insufficient ingredient',
        'unit' => 'kg',
        'quantity' => 0.5,
        'minimum_limit' => 2,
        'unit_cost' => 1,
    ]);
    $ingredient->products()->attach($product->id, ['quantity_used' => 1]);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);
    $order->orderItems()->create([
        'product_id' => $product->id, 'quantity' => 1,
        'unit_price' => 100, 'total_price' => 100,
    ]);

    try {
        app(OrderService::class)->accept($order, null, null, null);
        throw new \RuntimeException('Expected insufficient inventory validation.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('inventory');
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    expect((float) $ingredient->fresh()->quantity)->toBe(0.5);
});

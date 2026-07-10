<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns 422 when dashboard overview is called without restaurantId', function () {
    $response = $this->getJson('/api/v1/restaurant/dashboard/overview');

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['restaurantId']);
});

it('returns dashboard overview with kpis when restaurantId is provided', function () {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurant->id);

    $response->assertOk();
    $response->assertJsonStructure([
        'kpis' => [
            'todayTotalSales',
            'yesterdayTotalSales',
            'salesChangePercent',
            'todayOrders',
            'ordersByStatus',
            'lowStockAlertsCount',
            'orderActivityByHour',
            'lowStockProducts',
        ],
    ]);
});

it('returns dashboard overview scoped to the given restaurant', function () {
    $restaurantA = Restaurant::factory()->create(['is_active' => true]);
    $restaurantB = Restaurant::factory()->create(['is_active' => true]);

    Order::factory()->create([
        'restaurant_id' => $restaurantA->id,
        'status' => OrderStatus::Pending,
        'created_at' => now(),
    ]);
    Order::factory()->create([
        'restaurant_id' => $restaurantB->id,
        'status' => OrderStatus::Pending,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/restaurant/dashboard/overview?restaurantId='.$restaurantA->id);

    $response->assertOk();
    expect($response->json('kpis.todayOrders'))->toBe(1);
});

it('lists new orders with filter status pending and createdToday', function () {
    $restaurant = Restaurant::factory()->create();
    $customer = User::factory()->create(['email' => 'customer@example.com']);

    Order::factory()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Pending,
        'created_at' => now(),
    ]);
    Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Preparing,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/orders?filter[restaurantId]='.$restaurant->id.'&filter[status]=pending&filter[createdToday]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('lists orders in preparation with filter status preparing', function () {
    $restaurant = Restaurant::factory()->create();
    $customer = User::factory()->create(['email' => 'prep-customer@example.com']);

    Order::factory()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Preparing,
    ]);
    Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Pending,
    ]);

    $response = $this->getJson('/api/v1/orders?filter[restaurantId]='.$restaurant->id.'&filter[status]=preparing');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('accepts an order when preparation time is unknown', function () {
    $restaurant = Restaurant::factory()->create();
    $customer = User::factory()->create(['email' => 'accept-validation@example.com']);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Pending,
    ]);

    $response = $this->postJson('/api/v1/orders/'.$order->id.'/accept', []);

    $response->assertOk();
    $response->assertJsonPath('data.status', OrderStatus::Accepted->value);
    $response->assertJsonPath('data.estimatedPreparationMinutes', null);
});

it('accepts a pending order with preparation time and optional fields', function () {
    $restaurant = Restaurant::factory()->create();
    $customer = User::factory()->create(['email' => 'accept-customer@example.com']);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Pending,
    ]);

    $response = $this->postJson('/api/v1/orders/'.$order->id.'/accept', [
        'preparationTimeMinutes' => 25,
        'assignedEmployeeId' => null,
        'kitchenNotes' => 'Extra sauce',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', OrderStatus::Accepted->value);
    $response->assertJsonPath('data.estimatedPreparationMinutes', 25);
    $response->assertJsonPath('data.kitchenNotes', 'Extra sauce');
    $response->assertJsonPath('message', 'Order accepted successfully.');
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => OrderStatus::Accepted->value,
        'estimated_preparation_minutes' => 25,
    ]);
});

it('rejects a pending order with reason and optional message', function () {
    $restaurant = Restaurant::factory()->create();
    $customer = User::factory()->create(['email' => 'reject-customer@example.com']);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Pending,
    ]);

    $response = $this->postJson('/api/v1/orders/'.$order->id.'/reject', [
        'reason' => 'out_of_stock',
        'customerMessage' => 'Ingredients unavailable',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', OrderStatus::Cancelled->value);
    $response->assertJsonPath('data.cancellationReasonCode', 'out_of_stock');
    $response->assertJsonPath('message', 'Order rejected successfully.');
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => OrderStatus::Cancelled->value,
        'cancellation_reason_code' => 'out_of_stock',
    ]);
});

it('lists low stock products with filter', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    Product::factory()->lowStock()->count(2)->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
    ]);
    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'stock_quantity' => 100,
        'low_stock_threshold' => 5,
    ]);

    $response = $this->getJson('/api/v1/products?filter[restaurantId]='.$restaurant->id.'&filter[lowStock]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

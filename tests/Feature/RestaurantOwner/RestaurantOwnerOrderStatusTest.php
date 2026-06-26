<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'phone' => '+963933000101',
    ]);
    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);
    Sanctum::actingAs($this->owner);
});

it('changes restaurant owner order status using valid lifecycle transitions', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Accepted->value,
    ]);

    $response = $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/status", [
        'status' => OrderStatus::Preparing->value,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', OrderStatus::Preparing->value);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Preparing);
    expect($order->preparing_at)->not->toBeNull();
});

it('rejects invalid restaurant owner order status transitions', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Accepted->value,
    ]);

    $response = $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/status", [
        'status' => OrderStatus::Completed->value,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('status');
});

it('forbids changing order status for another restaurant', function () {
    $otherRestaurant = Restaurant::factory()->create();
    $order = Order::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'status' => OrderStatus::Accepted->value,
    ]);

    $response = $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/status", [
        'status' => OrderStatus::Preparing->value,
    ]);

    $response->assertForbidden();
});

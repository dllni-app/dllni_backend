<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns invoice data for order', function () {
    $restaurant = Restaurant::factory()->create();
    $customer = User::factory()->create(['email' => 'invoice-customer@example.com']);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'status' => OrderStatus::Completed,
    ]);

    $response = $this->getJson('/api/v1/orders/'.$order->id.'/invoice');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'orderNumber',
            'orderId',
            'status',
            'createdAt',
            'customer' => ['id', 'name', 'email'],
            'restaurant' => ['id', 'name', 'address'],
            'items',
            'subtotal',
            'totalAmount',
        ],
    ]);
});

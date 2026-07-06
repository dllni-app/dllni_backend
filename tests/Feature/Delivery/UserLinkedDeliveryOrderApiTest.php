<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

it('returns linked delivery data and real tracking for restaurant orders', function (): void {
    $user = User::factory()->create();
    $company = DeliveryCompany::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'order_type' => OrderType::Delivery->value,
        'status' => OrderStatus::ReadyForPickup->value,
        'ready_for_pickup_at' => now()->subMinutes(5),
    ]);

    $deliveryOrder = DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'created_by_user_id' => $user->id,
        'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER,
        'source_id' => $order->id,
        'status' => DeliveryOrderStatus::Accepted->value,
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user/orders?section=restaurant')
        ->assertOk()
        ->assertJsonPath('data.0.deliveryOrderId', $deliveryOrder->id)
        ->assertJsonPath('data.0.deliverySummary.enabled', true);

    $this->getJson('/api/v1/user/orders/restaurant/'.$order->id.'/tracking')
        ->assertOk()
        ->assertJsonPath('deliveryOrderId', $deliveryOrder->id)
        ->assertJsonStructure(['delivery', 'eta', 'map', 'timeline', 'merchant', 'actions']);
});

it('returns linked delivery data and real tracking for supermarket orders', function (): void {
    $user = User::factory()->create();
    $company = DeliveryCompany::factory()->create();
    $order = SmOrder::factory()->create([
        'customer_id' => $user->id,
        'status' => SmOrderStatus::ReadyForPickup->value,
        'ready_for_pickup_at' => now()->subMinutes(5),
    ]);

    $deliveryOrder = DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'created_by_user_id' => $user->id,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
        'status' => DeliveryOrderStatus::Accepted->value,
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user/orders?section=supermarket')
        ->assertOk()
        ->assertJsonPath('data.0.deliveryOrderId', $deliveryOrder->id)
        ->assertJsonPath('data.0.deliverySummary.enabled', true)
        ->assertJsonPath('data.0.fulfillment.type', 'delivery');

    $this->getJson('/api/v1/user/orders/supermarket/'.$order->id.'/tracking')
        ->assertOk()
        ->assertJsonPath('deliveryOrderId', $deliveryOrder->id)
        ->assertJsonStructure(['delivery', 'eta', 'map', 'timeline', 'merchant', 'actions']);
});

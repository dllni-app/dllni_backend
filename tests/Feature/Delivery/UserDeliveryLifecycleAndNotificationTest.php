<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryUserNotification;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Services\DeliverySourceOrderSyncService;
use Modules\Delivery\Services\DeliveryUserNotificationService;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

it('syncs picked up and completed delivery statuses to restaurant source order', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'order_type' => OrderType::Delivery->value,
        'status' => OrderStatus::ReadyForPickup->value,
    ]);
    $deliveryOrder = DeliveryOrder::factory()->create([
        'created_by_user_id' => $user->id,
        'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER,
        'source_id' => $order->id,
    ]);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::PickedUp, 'picked up test');
    expect($order->fresh()->status->value)->toBe(OrderStatus::PickedUp->value);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::Completed, 'completed test');
    expect($order->fresh()->status->value)->toBe(OrderStatus::Completed->value);
});

it('syncs picked up and completed delivery statuses to supermarket source order', function (): void {
    $user = User::factory()->create();
    $order = SmOrder::factory()->create([
        'customer_id' => $user->id,
        'status' => SmOrderStatus::ReadyForPickup->value,
    ]);
    $deliveryOrder = DeliveryOrder::factory()->create([
        'created_by_user_id' => $user->id,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
    ]);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::PickedUp, 'picked up test');
    expect($order->fresh()->status->value)->toBe(SmOrderStatus::PickedUp->value);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::Completed, 'completed test');
    expect($order->fresh()->status->value)->toBe(SmOrderStatus::Completed->value);
});

it('does not cancel a restaurant source order when delivery dispatch is stopped', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'order_type' => OrderType::Delivery->value,
        'status' => OrderStatus::Pending->value,
    ]);
    $deliveryOrder = DeliveryOrder::factory()->create([
        'created_by_user_id' => $user->id,
        'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER,
        'source_id' => $order->id,
    ]);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::Stopped, 'No drivers available');

    $order->refresh();

    expect($order->status->value)->toBe(OrderStatus::Pending->value);
    expect($order->cancelled_at)->toBeNull();
});

it('does not cancel a supermarket source order when delivery dispatch is stopped', function (): void {
    $user = User::factory()->create();
    $order = SmOrder::factory()->create([
        'customer_id' => $user->id,
        'status' => SmOrderStatus::Pending->value,
    ]);
    $deliveryOrder = DeliveryOrder::factory()->create([
        'created_by_user_id' => $user->id,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
    ]);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::Stopped, 'No drivers available');

    $order->refresh();

    expect($order->status->value)->toBe(SmOrderStatus::Pending->value);
    expect($order->cancelled_at)->toBeNull();
});

it('sends user delivery notification payload with tracking deep link', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $deliveryOrder = DeliveryOrder::factory()->create([
        'created_by_user_id' => $user->id,
        'order_number' => 'DEL-TEST-1000',
    ]);

    app(DeliveryUserNotificationService::class)->notifyPickedUp($deliveryOrder);

    Notification::assertSentTo($user, DeliveryUserNotification::class, function (DeliveryUserNotification $notification) use ($user, $deliveryOrder): bool {
        $payload = $notification->toArray($user);

        return ($payload['module'] ?? null) === 'delivery'
            && ($payload['data']['deepLinkTarget'] ?? null) === 'delivery_order_tracking'
            && (int) ($payload['data']['orderId'] ?? 0) === (int) $deliveryOrder->id;
    });
});

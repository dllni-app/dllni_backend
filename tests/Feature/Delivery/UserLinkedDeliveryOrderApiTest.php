<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryUserNotification;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Services\DeliverySourceOrderSyncService;
use Modules\Delivery\Services\DeliveryUserNotificationService;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

function deliveryTestAddress(User $user, array $overrides = []): UserAddress
{
    return UserAddress::factory()->create([
        'user_id' => $user->id,
        'latitude' => 36.202104,
        'longitude' => 37.134260,
        ...$overrides,
    ]);
}

function deliveryTestRestaurantCart(User $user, array $restaurantOverrides = []): Cart
{
    $restaurant = Restaurant::factory()->create([
        'latitude' => 36.210000,
        'longitude' => 37.150000,
        ...$restaurantOverrides,
    ]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'price' => 12000,
    ]);
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 12000,
        'total_price' => 12000,
        'signature_hash' => 'test-'.Str::uuid()->toString(),
    ]);

    return $cart->fresh(['restaurant', 'items.product']);
}

function deliveryTestSupermarketCart(User $user, array $storeOverrides = []): SmCart
{
    $store = SmStore::factory()->create([
        'latitude' => 36.220000,
        'longitude' => 37.170000,
        ...$storeOverrides,
    ]);
    $product = SmProduct::factory()->create([
        'store_id' => $store->id,
        'price' => 9000,
    ]);
    $cart = SmCart::query()->create([
        'user_id' => $user->id,
        'store_id' => $store->id,
    ]);
    SmCartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 9000,
    ]);

    return $cart->fresh(['store', 'items.product.store']);
}

it('restaurant delivery checkout creates a linked delivery order', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = deliveryTestAddress($user);
    $cart = deliveryTestRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.deliverySummary.enabled', true);

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeInstanceOf(DeliveryOrder::class)
        ->and($order->deliveryOrder->source_type)->toBe(DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER)
        ->and((int) $order->deliveryOrder->created_by_user_id)->toBe((int) $user->id);
});

it('restaurant pickup checkout does not create a delivery order', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $cart = deliveryTestRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'pickup',
        'receiveMode' => 'immediate',
    ])->assertCreated();

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeNull();
});

it('supermarket delivery checkout creates a linked delivery order and uses the selected address', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = deliveryTestAddress($user, ['mobile' => '0999999999']);
    $cart = deliveryTestSupermarketCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.deliverySummary.enabled', true);

    $order = SmOrder::query()->where('customer_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeInstanceOf(DeliveryOrder::class)
        ->and($order->deliveryOrder->source_type)->toBe(DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER)
        ->and((float) $order->deliveryOrder->dropoff_latitude)->toBe((float) $address->latitude)
        ->and((float) $order->deliveryOrder->dropoff_longitude)->toBe((float) $address->longitude)
        ->and($order->deliveryOrder->customer_phone)->toBe('0999999999');
});

it('returns linked delivery data and real tracking for restaurant orders', function (): void {
    $user = User::factory()->create();
    $company = DeliveryCompany::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'order_type' => OrderType::Delivery->value, 'status' => OrderStatus::ReadyForPickup->value, 'ready_for_pickup_at' => now()->subMinutes(5)]);
    $deliveryOrder = DeliveryOrder::factory()->create(['company_id' => $company->id, 'created_by_user_id' => $user->id, 'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER, 'source_id' => $order->id, 'status' => DeliveryOrderStatus::Accepted->value, 'accepted_at' => now()]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user/orders?section=restaurant')->assertOk()->assertJsonPath('data.0.deliveryOrderId', $deliveryOrder->id)->assertJsonPath('data.0.deliverySummary.enabled', true);
    $this->getJson('/api/v1/user/orders/restaurant/'.$order->id)->assertOk()->assertJsonPath('data.deliveryOrderId', $deliveryOrder->id);
    $this->getJson('/api/v1/user/orders/restaurant/'.$order->id.'/tracking')->assertOk()->assertJsonPath('deliveryOrderId', $deliveryOrder->id)->assertJsonStructure(['delivery', 'eta', 'map', 'timeline', 'merchant', 'actions']);
});

it('returns linked delivery data and real tracking for supermarket orders', function (): void {
    $user = User::factory()->create();
    $company = DeliveryCompany::factory()->create();
    $order = SmOrder::factory()->create(['customer_id' => $user->id, 'status' => SmOrderStatus::ReadyForPickup->value, 'ready_for_pickup_at' => now()->subMinutes(5)]);
    $deliveryOrder = DeliveryOrder::factory()->create(['company_id' => $company->id, 'created_by_user_id' => $user->id, 'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER, 'source_id' => $order->id, 'status' => DeliveryOrderStatus::Accepted->value, 'accepted_at' => now()]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user/orders?section=supermarket')->assertOk()->assertJsonPath('data.0.deliveryOrderId', $deliveryOrder->id)->assertJsonPath('data.0.deliverySummary.enabled', true)->assertJsonPath('data.0.fulfillment.type', 'delivery');
    $this->getJson('/api/v1/user/orders/supermarket/'.$order->id)->assertOk()->assertJsonPath('data.deliveryOrderId', $deliveryOrder->id);
    $this->getJson('/api/v1/user/orders/supermarket/'.$order->id.'/tracking')->assertOk()->assertJsonPath('deliveryOrderId', $deliveryOrder->id)->assertJsonStructure(['delivery', 'eta', 'map', 'timeline', 'merchant', 'actions']);
});

it('rejects access to another users delivery order', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $deliveryOrder = DeliveryOrder::factory()->create(['created_by_user_id' => $owner->id]);

    Sanctum::actingAs($other);

    $this->getJson('/api/v1/delivery/user/orders/'.$deliveryOrder->id)->assertNotFound();
});

it('syncs picked up and completed delivery statuses to restaurant source order', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'order_type' => OrderType::Delivery->value, 'status' => OrderStatus::ReadyForPickup->value]);
    $deliveryOrder = DeliveryOrder::factory()->create(['created_by_user_id' => $user->id, 'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER, 'source_id' => $order->id]);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::PickedUp, 'picked up test');
    expect($order->fresh()->status->value)->toBe(OrderStatus::PickedUp->value);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::Completed, 'completed test');
    expect($order->fresh()->status->value)->toBe(OrderStatus::Completed->value);
});

it('syncs picked up and completed delivery statuses to supermarket source order', function (): void {
    $user = User::factory()->create();
    $order = SmOrder::factory()->create(['customer_id' => $user->id, 'status' => SmOrderStatus::ReadyForPickup->value]);
    $deliveryOrder = DeliveryOrder::factory()->create(['created_by_user_id' => $user->id, 'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER, 'source_id' => $order->id]);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::PickedUp, 'picked up test');
    expect($order->fresh()->status->value)->toBe(SmOrderStatus::PickedUp->value);

    app(DeliverySourceOrderSyncService::class)->sync($deliveryOrder, DeliveryOrderStatus::Completed, 'completed test');
    expect($order->fresh()->status->value)->toBe(SmOrderStatus::Completed->value);
});

it('sends user delivery notification payload with tracking deep link', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $deliveryOrder = DeliveryOrder::factory()->create(['created_by_user_id' => $user->id, 'order_number' => 'DEL-TEST-1000']);

    app(DeliveryUserNotificationService::class)->notifyPickedUp($deliveryOrder);

    Notification::assertSentTo($user, DeliveryUserNotification::class, function (DeliveryUserNotification $notification) use ($user, $deliveryOrder): bool {
        $payload = $notification->toArray($user);
        return ($payload['module'] ?? null) === 'delivery'
            && ($payload['data']['deepLinkTarget'] ?? null) === 'delivery_order_tracking'
            && (int) ($payload['data']['orderId'] ?? 0) === (int) $deliveryOrder->id;
    });
});

it('rejects restaurant delivery checkout when user address coordinates are missing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = deliveryTestAddress($user, ['latitude' => null, 'longitude' => null]);
    $cart = deliveryTestRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', ['fulfillmentType' => 'delivery', 'receiveMode' => 'immediate', 'addressId' => $address->id])->assertStatus(422)->assertJsonValidationErrors(['delivery']);
    expect(Order::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects restaurant delivery checkout when merchant coordinates are missing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = deliveryTestAddress($user);
    $cart = deliveryTestRestaurantCart($user, ['latitude' => null, 'longitude' => null]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', ['fulfillmentType' => 'delivery', 'receiveMode' => 'immediate', 'addressId' => $address->id])->assertStatus(422)->assertJsonValidationErrors(['delivery']);
    expect(Order::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

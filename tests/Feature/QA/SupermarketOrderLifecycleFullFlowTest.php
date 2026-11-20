<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\DeliveryOrderFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Services\DriverDispatchService;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

it('completes customer checkout, store preparation and courier delivery as one linked supermarket order', function (): void {
    Queue::fake();

    $customer = User::factory()->create();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $company = DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $driver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 36.220100,
        'longitude' => 37.170100,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);

    $store = SmStore::factory()->create([
        'owner_user_id' => $owner->id,
        'is_active' => true,
        'latitude' => 36.220000,
        'longitude' => 37.170000,
    ]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'is_available' => true,
        'price' => 9000,
        'stock_quantity' => 20,
    ]);
    $address = UserAddress::factory()->create([
        'user_id' => $customer->id,
        'latitude' => 36.202104,
        'longitude' => 37.134260,
    ]);

    Sanctum::actingAs($customer);
    $added = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();
    $cartId = (int) $added->json('data.id');

    $this->postJson("/api/v1/user/supermarket/carts/{$cartId}/orders", [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated()->assertJsonPath('data.status', SmOrderStatus::Pending->value);

    $order = SmOrder::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($order->deliveryOrder)->not->toBeNull()
        ->and((int) $product->fresh()->stock_quantity)->toBe(18);
    $delivery = $order->deliveryOrder;
    expect($delivery->status)->toBe(DeliveryOrderStatus::WaitingMerchantReady->value);

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::Accepted->value);
    expect((int) $product->fresh()->stock_quantity)->toBe(18);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/preparing")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::Preparing->value);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::ReadyForPickup->value);

    app(DriverDispatchService::class)->dispatchByOrderId($delivery->id);
    $attempt = DeliveryAssignmentAttempt::query()
        ->where('order_id', $delivery->id)
        ->where('driver_id', $driver->id)
        ->firstOrFail();

    Sanctum::actingAs($driver->user);
    $this->getJson('/api/v1/delivery/driver/offers/current')->assertOk();
    $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept")
        ->assertOk()->assertJsonPath('data.status', DeliveryOrderStatus::Accepted->value);
    $this->postJson("/api/v1/delivery/driver/orders/{$delivery->id}/start")->assertOk();
    $this->postJson("/api/v1/delivery/driver/orders/{$delivery->id}/arrived-pickup")->assertOk();

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::ReadyForPickup->value);
    expect($order->fresh()->store_handover_confirmed_at)->not->toBeNull();

    Sanctum::actingAs($driver->user);
    $this->postJson("/api/v1/delivery/driver/orders/{$delivery->id}/pickup")
        ->assertOk()->assertJsonPath('data.status', DeliveryOrderStatus::PickedUp->value);
    expect($order->fresh()->status)->toBe(SmOrderStatus::PickedUp);
    $this->postJson("/api/v1/delivery/driver/orders/{$delivery->id}/arrived-dropoff")->assertOk();
    $this->postJson("/api/v1/delivery/driver/orders/{$delivery->id}/deliver")
        ->assertOk()->assertJsonPath('data.status', DeliveryOrderStatus::Completed->value);
    expect($order->fresh()->status)->toBe(SmOrderStatus::Completed)
        ->and($driver->fresh()->availability_status)->toBe('available')
        ->and((int) $product->fresh()->stock_quantity)->toBe(18);

    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/user/orders/supermarket/{$order->id}/tracking")
        ->assertOk()->assertJsonPath('data.merchant.id', $store->id);
});

it('refuses owner cancellation after courier acceptance without changing either order', function (): void {
    Queue::fake();

    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStoreFactory::new()->create(['owner_user_id' => $owner->id]);
    $order = SmOrderFactory::new()->readyForPickup()->create(['store_id' => $store->id]);

    $company = DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $driver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 36.220100,
        'longitude' => 37.170100,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);
    $delivery = DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::SearchingForDriver->value,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
        'pickup_latitude' => 36.220000,
        'pickup_longitude' => 37.170000,
    ]);

    app(DriverDispatchService::class)->dispatchByOrderId($delivery->id);
    $attempt = DeliveryAssignmentAttempt::query()->where('order_id', $delivery->id)->firstOrFail();
    Sanctum::actingAs($driver->user);
    $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept")
        ->assertOk()->assertJsonPath('data.status', DeliveryOrderStatus::Accepted->value);
    expect($driver->fresh()->availability_status)->toBe('busy');

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/cancel", [
        'reason' => 'Store cannot fulfill the remaining items.',
    ])->assertNotFound();

    // This release has no cancellation/refund/stock-return workflow.
    // A rejected owner action must not silently cancel an assigned courier.
    expect($order->fresh()->status)->toBe(SmOrderStatus::ReadyForPickup)
        ->and($order->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->fresh()->status)->toBe(DeliveryOrderStatus::Accepted->value)
        ->and($driver->fresh()->availability_status)->toBe('busy');
});

it('keeps picked-up deliveries unchanged when an obsolete cancellation route is requested', function (): void {
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStoreFactory::new()->create(['owner_user_id' => $owner->id]);
    // A source-status sync can briefly lag behind the delivery transition.
    $order = SmOrderFactory::new()->readyForPickup()->create(['store_id' => $store->id]);
    $delivery = DeliveryOrderFactory::new()->create([
        'status' => DeliveryOrderStatus::PickedUp->value,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
    ]);

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/cancel", [
        'reason' => 'Too late to cancel after courier pickup.',
    ])->assertNotFound();

    expect($order->fresh()->status)->toBe(SmOrderStatus::ReadyForPickup)
        ->and($order->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->fresh()->status)->toBe(DeliveryOrderStatus::PickedUp->value);
});

it('completes pickup from customer checkout through owner without courier and deducts inventory once', function (): void {
    Queue::fake();

    $customer = User::factory()->create();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStore::factory()->create([
        'owner_user_id' => $owner->id,
        'is_active' => true,
    ]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'is_available' => true,
        'price' => 9000,
        'stock_quantity' => 20,
    ]);

    Sanctum::actingAs($customer);
    $added = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $cartId = (int) $added->json('data.id');
    $this->postJson("/api/v1/user/supermarket/carts/{$cartId}/orders", [
        'fulfillmentType' => 'pickup',
        'receiveMode' => 'immediate',
    ])->assertCreated()->assertJsonPath('data.status', SmOrderStatus::Pending->value);

    $order = SmOrder::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeNull()
        ->and((int) $product->fresh()->stock_quantity)->toBe(18);

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::Accepted->value);
    expect((int) $product->fresh()->stock_quantity)->toBe(18);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/preparing")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::Preparing->value);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::ReadyForPickup->value);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/customer-pickup-complete")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::Completed->value);

    expect($order->fresh()->deliveryOrder)->toBeNull()
        ->and((int) $product->fresh()->stock_quantity)->toBe(18);
});

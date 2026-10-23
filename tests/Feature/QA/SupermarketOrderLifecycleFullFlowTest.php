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
    expect($order->deliveryOrder)->not->toBeNull();
    $delivery = $order->deliveryOrder;
    expect($delivery->status)->toBe(DeliveryOrderStatus::WaitingMerchantReady->value);

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::Accepted->value);
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
        ->and($driver->fresh()->availability_status)->toBe('available');

    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/user/orders/supermarket/{$order->id}/tracking")
        ->assertOk()->assertJsonPath('data.merchant.id', $store->id);
});

it('cancels the linked accepted courier and releases the driver when the store cancels before pickup', function (): void {
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
    ])->assertOk()->assertJsonPath('data.status', SmOrderStatus::Cancelled->value);

    expect($order->fresh()->status)->toBe(SmOrderStatus::Cancelled)
        ->and($delivery->fresh()->status)->toBe(DeliveryOrderStatus::Cancelled->value)
        ->and($driver->fresh()->availability_status)->toBe('available');

    Sanctum::actingAs($driver->user);
    $this->postJson("/api/v1/delivery/driver/orders/{$delivery->id}/pickup")->assertStatus(422);
});

it('does not partially cancel the source order when the courier has already picked up', function (): void {
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
    ])->assertStatus(400);

    expect($order->fresh()->status)->toBe(SmOrderStatus::ReadyForPickup)
        ->and($order->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->fresh()->status)->toBe(DeliveryOrderStatus::PickedUp->value);
});

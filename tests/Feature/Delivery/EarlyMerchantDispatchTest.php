<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\DeliveryOrderFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Services\DriverDispatchService;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;

function earlyDispatchDriver(DeliveryCompany $company, float $longitude = 36.2767): DeliveryDriver
{
    $driver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5140,
        'longitude' => $longitude,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);

    return $driver->fresh();
}

function linkedMerchantDelivery(Order|SmOrder $merchantOrder, DeliveryCompany $company): \Modules\Delivery\Models\DeliveryOrder
{
    return DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::WaitingMerchantReady->value,
        'pickup_latitude' => 33.5138,
        'pickup_longitude' => 36.2765,
        'source_type' => $merchantOrder instanceof Order
            ? DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER
            : DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $merchantOrder->id,
    ]);
}

it('starts supermarket dispatch on acceptance with an optional estimate', function (): void {
    Queue::fake();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStore::factory()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->pending()->create(['store_id' => $store->id]);
    $company = DeliveryCompany::factory()->create();
    $deliveryOrder = linkedMerchantDelivery($order, $company);
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept", [
        'preparationTimeMinutes' => 25,
    ])->assertOk()
        ->assertJsonPath('data.status', SmOrderStatus::Accepted->value)
        ->assertJsonPath('data.estimatedPreparationMinutes', 25);

    $deliveryOrder->refresh();
    expect($deliveryOrder->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value)
        ->and($deliveryOrder->merchant_status)->toBe(SmOrderStatus::Accepted->value)
        ->and($deliveryOrder->estimated_ready_at)->not->toBeNull();
});

it('allows travel before restaurant readiness but rejects pickup until ready', function (): void {
    Queue::fake();
    $owner = User::factory()->create(['module_type' => UserModuleType::RestaurantSeller->value]);
    $restaurant = Restaurant::factory()->create(['user_id' => $owner->id]);
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Pending,
    ]);
    $company = DeliveryCompany::factory()->create();
    $deliveryOrder = linkedMerchantDelivery($order, $company);
    $driver = earlyDispatchDriver($company);
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/orders/{$order->id}/accept", [])->assertOk();
    app(DriverDispatchService::class)->dispatchByOrderId($deliveryOrder->id);
    $attempt = DeliveryAssignmentAttempt::query()->where('order_id', $deliveryOrder->id)->firstOrFail();

    Sanctum::actingAs($driver->user);
    $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept")->assertOk();
    $this->postJson("/api/v1/delivery/driver/orders/{$deliveryOrder->id}/start")->assertOk();
    $this->postJson("/api/v1/delivery/driver/orders/{$deliveryOrder->id}/pickup")
        ->assertConflict()
        ->assertJsonPath('code', 'merchant_not_ready')
        ->assertJsonPath('merchantPreparation.hasEstimate', false);

    Sanctum::actingAs($owner);
    $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/status", ['status' => OrderStatus::Preparing->value])->assertOk();
    $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/status", ['status' => OrderStatus::ReadyForPickup->value])->assertOk();

    Sanctum::actingAs($driver->user);
    $this->postJson("/api/v1/delivery/driver/orders/{$deliveryOrder->id}/pickup")
        ->assertOk()
        ->assertJsonPath('data.status', DeliveryOrderStatus::PickedUp->value);
});

it('expands beyond fifteen kilometres and reoffers to earlier drivers', function (): void {
    Queue::fake();
    $company = DeliveryCompany::factory()->create();
    $nearDriver = earlyDispatchDriver($company);
    $farDriver = earlyDispatchDriver($company, 36.55);
    $deliveryOrder = DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::SearchingForDriver->value,
        'pickup_latitude' => 33.5138,
        'pickup_longitude' => 36.2765,
    ]);

    app(DriverDispatchService::class)->dispatchByOrderId($deliveryOrder->id);
    $firstAttempt = DeliveryAssignmentAttempt::query()->where('order_id', $deliveryOrder->id)->where('driver_id', $nearDriver->id)->firstOrFail();
    app(DriverDispatchService::class)->rejectAttempt($firstAttempt->id, $nearDriver, 'Unavailable');
    app(DriverDispatchService::class)->dispatchByOrderId($deliveryOrder->id);

    $deliveryOrder->refresh();
    expect((float) $deliveryOrder->search_radius_km)->toBeGreaterThan(15)
        ->and(DeliveryAssignmentAttempt::query()->where('order_id', $deliveryOrder->id)->where('driver_id', $nearDriver->id)->count())->toBe(2)
        ->and(DeliveryAssignmentAttempt::query()->where('order_id', $deliveryOrder->id)->where('driver_id', $farDriver->id)->exists())->toBeTrue();
});

it('revises and clears a supermarket preparation estimate', function (): void {
    Queue::fake();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStore::factory()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->pending()->create(['store_id' => $store->id]);
    $company = DeliveryCompany::factory()->create();
    $deliveryOrder = linkedMerchantDelivery($order, $company);
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept", [])->assertOk();
    $this->patchJson("/api/v1/store-owner/orders/{$order->id}/preparation-estimate", [
        'preparationTimeMinutes' => 40,
    ])->assertOk()
        ->assertJsonPath('data.estimatedPreparationMinutes', 40);

    expect($deliveryOrder->fresh()->estimated_preparation_minutes)->toBe(40)
        ->and($deliveryOrder->fresh()->estimated_ready_at)->not->toBeNull();

    $this->patchJson("/api/v1/store-owner/orders/{$order->id}/preparation-estimate", [
        'preparationTimeMinutes' => null,
    ])->assertOk()
        ->assertJsonPath('data.estimatedPreparationMinutes', null);

    expect($deliveryOrder->fresh()->estimated_preparation_minutes)->toBeNull()
        ->and($deliveryOrder->fresh()->estimated_ready_at)->toBeNull();
});

it('cancels supermarket dispatch after acceptance and releases the assigned driver', function (): void {
    Queue::fake();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStore::factory()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->pending()->create(['store_id' => $store->id]);
    $company = DeliveryCompany::factory()->create();
    $driver = earlyDispatchDriver($company);
    $deliveryOrder = linkedMerchantDelivery($order, $company);
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept", [])->assertOk();
    $driver->forceFill(['availability_status' => DeliveryDriverAvailabilityStatus::Busy->value])->save();
    $deliveryOrder->forceFill([
        'driver_id' => $driver->id,
        'status' => DeliveryOrderStatus::Accepted->value,
        'accepted_at' => now(),
    ])->save();
    $attempt = DeliveryAssignmentAttempt::query()->create([
        'order_id' => $deliveryOrder->id,
        'driver_id' => $driver->id,
        'attempt_no' => 1,
        'dispatch_wave' => 1,
        'candidate_tier' => 'located',
        'status' => 'open',
        'offered_at' => now(),
        'expires_at' => now()->addMinute(),
    ]);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/cancel", [
        'reason' => 'Store cannot fulfil the order.',
    ])->assertOk()
        ->assertJsonPath('data.status', SmOrderStatus::Cancelled->value);

    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::Cancelled->value)
        ->and($driver->fresh()->availability_status)->toBe(DeliveryDriverAvailabilityStatus::Available->value)
        ->and($attempt->fresh()->status)->toBe('cancelled');
});

it('preserves an active search when the supermarket becomes ready', function (): void {
    Queue::fake();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStore::factory()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Preparing,
        'accepted_at' => now()->subMinutes(10),
    ]);
    $company = DeliveryCompany::factory()->create();
    $deliveryOrder = linkedMerchantDelivery($order, $company);
    $deliveryOrder->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup")->assertOk();

    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value)
        ->and($deliveryOrder->fresh()->merchant_ready_at)->not->toBeNull();
    Queue::assertNotPushed(DispatchDeliveryOrderJob::class);
});

it('restarts a stopped unassigned search when the supermarket becomes ready', function (): void {
    Queue::fake();
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStore::factory()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Preparing,
        'accepted_at' => now()->subMinutes(10),
    ]);
    $company = DeliveryCompany::factory()->create();
    $deliveryOrder = linkedMerchantDelivery($order, $company);
    $deliveryOrder->forceFill([
        'status' => DeliveryOrderStatus::Stopped->value,
        'stopped_at' => now(),
        'stop_reason' => 'Pool exhausted',
    ])->save();
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup")->assertOk();

    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value)
        ->and($deliveryOrder->fresh()->stopped_at)->toBeNull();
    Queue::assertPushed(DispatchDeliveryOrderJob::class);
});

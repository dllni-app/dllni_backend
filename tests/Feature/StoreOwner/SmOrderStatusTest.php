<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\DeliveryOrderFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Supermarket\Enums\RejectionType;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmOrderStatusLog;

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($this->user);

    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->user->id,
        'trust_score' => 100,
    ]);
});

function createLinkedSupermarketDeliveryOrder(\Modules\Supermarket\Models\SmOrder $order, array $overrides = []): \Modules\Delivery\Models\DeliveryOrder
{
    $company = DeliveryCompany::factory()->create([
        'is_active' => true,
        'is_suspended' => false,
    ]);

    return DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::WaitingMerchantReady->value,
        'created_by_user_id' => $order->customer_id,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
        ...$overrides,
    ]);
}

// ============ ACCEPT ORDER TESTS ============

it('accepts a pending order and starts delivery dispatch', function (): void {
    Queue::fake();

    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);
    $deliveryOrder = createLinkedSupermarketDeliveryOrder($order);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept", [
        'preparationTimeMinutes' => 20,
    ]);

    $response->assertOk();
    expect($response->json('message'))->toBe('Order accepted successfully.');
    expect($response->json('data.status'))->toBe('accepted');
    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value);
    expect($response->json('data.estimatedPreparationMinutes'))->toBe(20);

    $this->assertDatabaseHas('sm_orders', [
        'id' => $order->id,
        'status' => 'accepted',
    ]);
    $this->assertDatabaseHas('sm_order_status_logs', [
        'order_id' => $order->id,
        'from_status' => SmOrderStatus::Pending->value,
        'to_status' => SmOrderStatus::Accepted->value,
        'changed_by_user_id' => $this->user->id,
    ]);

    Queue::assertPushed(DispatchDeliveryOrderJob::class);
});

it('rejects accepting non-pending orders', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Completed,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('Cannot accept order');
});

it('returns loaded relationships on accept', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");

    $response->assertOk();
    expect($response->json('data'))->toHaveKeys([
        'id',
        'status',
        'customer',
        'store',
        'items',
        'statusLogs',
        'disputes',
    ]);
});

// ============ PREPARING TESTS ============

it('marks an accepted order as preparing without starting delivery dispatch', function (): void {
    Queue::fake();

    $order = SmOrderFactory::new()->accepted()->create([
        'store_id' => $this->store->id,
    ]);
    $deliveryOrder = createLinkedSupermarketDeliveryOrder($order);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/preparing");

    $response->assertOk();
    expect($response->json('message'))->toBe('Order marked as preparing successfully.');
    expect($response->json('data.status'))->toBe(SmOrderStatus::Preparing->value);
    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::WaitingMerchantReady->value);

    $this->assertDatabaseHas('sm_order_status_logs', [
        'order_id' => $order->id,
        'from_status' => SmOrderStatus::Accepted->value,
        'to_status' => SmOrderStatus::Preparing->value,
        'changed_by_user_id' => $this->user->id,
    ]);

    Queue::assertNotPushed(DispatchDeliveryOrderJob::class);
});

it('rejects preparing transition when order is not accepted', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/preparing");

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('Cannot mark order');
});

// ============ COURIER HANDOVER TESTS ============

it('confirms courier handover without replacing the driver pickup lifecycle', function (): void {
    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $this->store->id,
        'picked_up_at' => null,
        'store_handover_confirmed_at' => null,
    ]);
    $deliveryOrder = createLinkedSupermarketDeliveryOrder($order, [
        'status' => DeliveryOrderStatus::Accepted->value,
    ]);
    $driver = DeliveryDriver::factory()->create([
        'company_id' => $deliveryOrder->company_id,
    ]);
    $deliveryOrder->update(['driver_id' => $driver->id]);
    $beforeCount = SmOrderStatusLog::query()->where('order_id', $order->id)->count();

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover");

    $response->assertOk();
    expect($response->json('message'))->toBe('Store handover confirmed. Pickup status is controlled by the delivery lifecycle.');
    expect($response->json('data.status'))->toBe(SmOrderStatus::ReadyForPickup->value);
    expect($response->json('data.storeHandoverConfirmedAt'))->not->toBeNull();

    $order->refresh();
    expect($order->status)->toBe(SmOrderStatus::ReadyForPickup);
    expect($order->picked_up_at)->toBeNull();
    expect($order->store_handover_confirmed_at)->not->toBeNull();
    expect($order->store_handover_confirmed_by_user_id)->toBe($this->user->id);
    expect(SmOrderStatusLog::query()->where('order_id', $order->id)->count())->toBe($beforeCount);
});

it('marks an accepted order ready for pickup and starts linked delivery dispatch', function (): void {
    Queue::fake();

    $order = SmOrderFactory::new()->accepted()->create([
        'store_id' => $this->store->id,
        'ready_for_pickup_at' => null,
    ]);
    $deliveryOrder = createLinkedSupermarketDeliveryOrder($order);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup");

    $response->assertOk();
    expect($response->json('message'))->toBe('Order marked as ready for pickup successfully.');
    expect($response->json('data.status'))->toBe(SmOrderStatus::ReadyForPickup->value);
    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value);

    $this->assertDatabaseHas('sm_order_status_logs', [
        'order_id' => $order->id,
        'from_status' => SmOrderStatus::Accepted->value,
        'to_status' => SmOrderStatus::ReadyForPickup->value,
        'changed_by_user_id' => $this->user->id,
    ]);

    Queue::assertPushed(DispatchDeliveryOrderJob::class, fn (DispatchDeliveryOrderJob $job): bool => true);
});

it('marks a preparing order ready for pickup and starts linked delivery dispatch', function (): void {
    Queue::fake();

    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Preparing,
        'ready_for_pickup_at' => null,
    ]);
    $deliveryOrder = createLinkedSupermarketDeliveryOrder($order);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup");

    $response->assertOk();
    expect($response->json('message'))->toBe('Order marked as ready for pickup successfully.');
    expect($response->json('data.status'))->toBe(SmOrderStatus::ReadyForPickup->value);
    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value);

    $this->assertDatabaseHas('sm_order_status_logs', [
        'order_id' => $order->id,
        'from_status' => SmOrderStatus::Preparing->value,
        'to_status' => SmOrderStatus::ReadyForPickup->value,
        'changed_by_user_id' => $this->user->id,
    ]);

    Queue::assertPushed(DispatchDeliveryOrderJob::class, fn (DispatchDeliveryOrderJob $job): bool => true);
});

it('retries linked delivery dispatch when ready for pickup is called again', function (): void {
    Queue::fake();

    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $this->store->id,
    ]);
    $deliveryOrder = createLinkedSupermarketDeliveryOrder($order);
    $beforeCount = SmOrderStatusLog::query()->where('order_id', $order->id)->count();

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/ready-for-pickup");

    $response->assertOk();
    expect($response->json('data.status'))->toBe(SmOrderStatus::ReadyForPickup->value);
    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::SearchingForDriver->value);
    expect(SmOrderStatusLog::query()->where('order_id', $order->id)->count())->toBe($beforeCount);

    Queue::assertPushed(DispatchDeliveryOrderJob::class, fn (DispatchDeliveryOrderJob $job): bool => true);
});

it('rejects courier handover when order is not ready_for_pickup', function (): void {
    $order = SmOrderFactory::new()->accepted()->create([
        'store_id' => $this->store->id,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover");

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('Cannot confirm courier handover');
});

it('courier handover is idempotent when already picked_up', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::PickedUp,
        'ready_for_pickup_at' => now()->subHour(),
        'picked_up_at' => now()->subMinutes(30),
        'store_handover_confirmed_at' => now()->subMinutes(31),
        'store_handover_confirmed_by_user_id' => $this->user->id,
    ]);

    $beforeCount = SmOrderStatusLog::query()->where('order_id', $order->id)->count();

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('picked_up');

    expect(SmOrderStatusLog::query()->where('order_id', $order->id)->count())->toBe($beforeCount);
});

// ============ CUSTOMER PICKUP COMPLETION TESTS ============

it('completes an immediate in-store pickup without a delivery order', function (): void {
    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $this->store->id,
        'pickup_mode' => SmPickupMode::ImmediatePickup,
        'customer_pickup_confirmed_at' => null,
    ]);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/customer-pickup-complete");

    $response->assertOk();
    expect($response->json('message'))->toBe('Customer pickup completed successfully.');
    expect($response->json('data.status'))->toBe(SmOrderStatus::Completed->value);
    expect($response->json('data.fulfillmentType'))->toBe('pickup');
    expect($response->json('data.customerPickupConfirmedAt'))->not->toBeNull();

    $order->refresh();
    expect($order->status)->toBe(SmOrderStatus::Completed);
    expect($order->customer_pickup_confirmed_at)->not->toBeNull();
    expect($order->deliveryOrder()->exists())->toBeFalse();

    $this->assertDatabaseHas('sm_order_status_logs', [
        'order_id' => $order->id,
        'from_status' => SmOrderStatus::ReadyForPickup->value,
        'to_status' => SmOrderStatus::Completed->value,
        'changed_by_user_id' => $this->user->id,
    ]);
});

it('completes a scheduled in-store pickup without a delivery order', function (): void {
    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $this->store->id,
        'pickup_mode' => SmPickupMode::ScheduledPickup,
        'pickup_scheduled_for' => now()->addHour(),
        'customer_pickup_confirmed_at' => null,
    ]);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/customer-pickup-complete")
        ->assertOk()
        ->assertJsonPath('data.status', SmOrderStatus::Completed->value)
        ->assertJsonPath('data.fulfillmentType', 'pickup');

    expect($order->fresh()->customer_pickup_confirmed_at)->not->toBeNull();
});

it('keeps customer pickup completion idempotent after success', function (): void {
    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $this->store->id,
        'customer_pickup_confirmed_at' => null,
    ]);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/customer-pickup-complete")
        ->assertOk();

    $beforeCount = SmOrderStatusLog::query()->where('order_id', $order->id)->count();

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/customer-pickup-complete")
        ->assertOk()
        ->assertJsonPath('data.status', SmOrderStatus::Completed->value);

    expect(SmOrderStatusLog::query()->where('order_id', $order->id)->count())->toBe($beforeCount);
});

it('rejects customer pickup completion for delivery orders', function (): void {
    $order = SmOrderFactory::new()->readyForPickup()->create([
        'store_id' => $this->store->id,
        'customer_pickup_confirmed_at' => null,
    ]);
    createLinkedSupermarketDeliveryOrder($order);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/customer-pickup-complete");

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('only available for pickup orders');
    expect($order->fresh()->status)->toBe(SmOrderStatus::ReadyForPickup);
    expect($order->fresh()->customer_pickup_confirmed_at)->toBeNull();
});

// Rejection and cancellation are unavailable in this release.
it('never exposes reject or cancel routes for non-refundable supermarket orders', function (): void {
    $order = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'status' => SmOrderStatus::Pending,
    ]);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", [
        'reason' => 'Not available',
        'rejectionType' => 'other',
    ])->assertNotFound();
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/cancel", [
        'reason' => 'No refunds',
    ])->assertNotFound();

    expect($order->fresh()->status)->toBe(SmOrderStatus::Pending);
    expect($order->fresh()->cancelled_at)->toBeNull();
});

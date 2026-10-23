<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DeliverySourceOrderSyncService;
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

    // Dispatch/arrival actions are covered separately by the delivery API tests.
    // Here, model the courier that has already accepted the linked offer.
    $delivery->update(['driver_id' => $driver->id, 'status' => DeliveryOrderStatus::Accepted->value]);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover")
        ->assertOk()->assertJsonPath('data.status', SmOrderStatus::ReadyForPickup->value);

    expect($order->fresh()->store_handover_confirmed_at)->not->toBeNull();
    app(DeliverySourceOrderSyncService::class)->sync($delivery, DeliveryOrderStatus::PickedUp, 'courier pickup');
    expect($order->fresh()->status)->toBe(SmOrderStatus::PickedUp);
    app(DeliverySourceOrderSyncService::class)->sync($delivery, DeliveryOrderStatus::Completed, 'delivered to customer');
    expect($order->fresh()->status)->toBe(SmOrderStatus::Completed);

    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/user/orders/supermarket/{$order->id}/tracking")
        ->assertOk()->assertJsonPath('data.merchant.id', $store->id);
});

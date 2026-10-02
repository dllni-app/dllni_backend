<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\DeliveryOrderFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmCouponFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

it('keeps platform governance fields out of store owner profile updates', function (): void {
    $context = actingAsSupermarketSeller();
    $otherOwner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);

    $context->store->forceFill([
        'trust_score' => 87,
        'warning_count' => 2,
        'is_active' => true,
        'is_featured' => false,
        'suspension_until' => null,
    ])->save();

    $this->putJson('/api/v1/store-owner/store', [
        'description' => 'Owner editable description',
        'ownerUserId' => $otherOwner->id,
        'trustScore' => 1,
        'warningCount' => 99,
        'isActive' => false,
        'isFeatured' => true,
        'suspensionUntil' => now()->addYear()->toIso8601String(),
        'averageRating' => 1,
        'totalReviews' => 999,
    ])->assertOk()
        ->assertJsonPath('data.description', 'Owner editable description');

    $store = $context->store->fresh();

    expect((int) $store->owner_user_id)->toBe((int) $context->user->id)
        ->and((int) $store->trust_score)->toBe(87)
        ->and((int) $store->warning_count)->toBe(2)
        ->and((bool) $store->is_active)->toBeTrue()
        ->and((bool) $store->is_featured)->toBeFalse()
        ->and($store->suspension_until)->toBeNull()
        ->and((float) $store->average_rating)->not->toBe(1.0)
        ->and((int) $store->total_reviews)->not->toBe(999);
});

it('scopes categories offers coupons and orders to the authenticated seller store', function (): void {
    $context = actingAsSupermarketSeller();
    $otherStore = SmStoreFactory::new()->create();

    $ownCategory = SmCategoryFactory::new()->create(['store_id' => $context->store->id]);
    $otherCategory = SmCategoryFactory::new()->create(['store_id' => $otherStore->id]);

    $ownOffer = SmOfferFactory::new()->create(['store_id' => $context->store->id]);
    $otherOffer = SmOfferFactory::new()->create(['store_id' => $otherStore->id]);

    $ownCoupon = SmCouponFactory::new()->create(['store_id' => $context->store->id]);
    $otherCoupon = SmCouponFactory::new()->create(['store_id' => $otherStore->id]);

    $otherOrder = SmOrder::factory()->create(['store_id' => $otherStore->id]);

    $this->getJson('/api/v1/sm-categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownCategory->id);

    $this->getJson("/api/v1/sm-categories/{$otherCategory->id}")->assertForbidden();

    $this->getJson('/api/v1/sm-offers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownOffer->id);

    $this->getJson("/api/v1/sm-offers/{$otherOffer->id}")->assertForbidden();

    $this->getJson('/api/v1/sm-coupons')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownCoupon->id);

    $this->getJson("/api/v1/sm-coupons/{$otherCoupon->id}")->assertForbidden();
    $this->getJson("/api/v1/sm-orders/{$otherOrder->id}")->assertForbidden();
});

it('does not expose generic supermarket order mutation endpoints to store owners', function (): void {
    $context = actingAsSupermarketSeller();
    $order = SmOrder::factory()->pending()->create(['store_id' => $context->store->id]);

    $this->postJson('/api/v1/sm-orders', [])->assertMethodNotAllowed();
    $this->putJson("/api/v1/sm-orders/{$order->id}", ['totalAmount' => 1])->assertMethodNotAllowed();
    $this->deleteJson("/api/v1/sm-orders/{$order->id}")->assertMethodNotAllowed();
});

it('cancels the linked delivery order when the store rejects a pending supermarket order', function (): void {
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStoreFactory::new()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->pending()->create(['store_id' => $store->id]);

    $company = DeliveryCompany::factory()->create();
    $deliveryOrder = DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::WaitingMerchantReady->value,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
    ]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", [
        'reason' => 'Requested items are not available in the store.',
        'rejectionType' => 'out_of_stock',
    ])->assertOk()
        ->assertJsonPath('data.status', SmOrderStatus::Cancelled->value);

    expect($deliveryOrder->fresh()->status)->toBe(DeliveryOrderStatus::Cancelled->value)
        ->and($order->fresh()->status)->toBe(SmOrderStatus::Cancelled)
        ->and($order->fresh()->statusLogs()->where('to_status', SmOrderStatus::Cancelled->value)->exists())->toBeTrue();
});

it('records store courier handover without impersonating the driver pickup transition', function (): void {
    $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    $store = SmStoreFactory::new()->create(['owner_user_id' => $owner->id]);
    $order = SmOrder::factory()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::ReadyForPickup->value,
        'ready_for_pickup_at' => now(),
    ]);

    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->create(['company_id' => $company->id]);
    $deliveryOrder = DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
        'status' => DeliveryOrderStatus::Accepted->value,
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
    ]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/courier-handover")
        ->assertOk()
        ->assertJsonPath('data.status', SmOrderStatus::ReadyForPickup->value);

    $order->refresh();
    $deliveryOrder->refresh();

    expect($order->status)->toBe(SmOrderStatus::ReadyForPickup)
        ->and($order->picked_up_at)->toBeNull()
        ->and($order->store_handover_confirmed_at)->not->toBeNull()
        ->and((int) $order->store_handover_confirmed_by_user_id)->toBe((int) $owner->id)
        ->and($deliveryOrder->status)->toBe(DeliveryOrderStatus::Accepted->value);
});

it('keeps governance and internal relations out of the customer supermarket store payload', function (): void {
    $store = SmStoreFactory::new()->create([
        'is_active' => true,
        'trust_score' => 10,
        'warning_count' => 7,
        'is_featured' => true,
        'suspension_until' => null,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/stores/{$store->id}");

    $response->assertOk();

    $payload = $response->json('store');

    expect($payload)->toBeArray()
        ->and($payload)->toHaveKeys(['id', 'name', 'averageRating', 'totalReviews', 'isFeatured'])
        ->and($payload)->not->toHaveKeys([
            'ownerUserId',
            'owner',
            'trustScore',
            'warningCount',
            'suspensionUntil',
            'orders',
            'documents',
            'trustLogs',
            'dailyStats',
            'commissionRules',
            'assistantQueries',
            'recurringOrders',
            'staff',
        ]);
});

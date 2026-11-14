<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Enums\SmInventoryLogType;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Modules\Supermarket\Services\SmInventoryService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seller = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($this->seller);
    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->seller->id,
        'trust_score' => 100,
    ]);
});

function makeCustomerReservedOrder(object $test, bool $legacy = false): array
{
    $product = SmProductFactory::new()->create([
        'store_id' => $test->store->id,
        'stock_quantity' => 10,
    ]);
    $order = SmOrderFactory::new()->create([
        'store_id' => $test->store->id,
        'status' => 'pending',
    ]);
    SmOrderItemFactory::new()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);
    if ($legacy) {
        // Existing pending customer checkouts were deducted without an inventory log.
        $product->decrement('stock_quantity', 2);
    } else {
        app(SmInventoryService::class)->reserveStockForCustomerOrder(
            $order, (int) $product->id, 2
        );
    }
    SmOrderStatusLog::query()->create([
        'order_id' => $order->id,
        'from_status' => null,
        'to_status' => 'pending',
        'notes' => 'Order placed by customer.',
        'changed_by_user_id' => $order->customer_id,
    ]);

    return [$order, $product];
}

it('customer order reserves 10 minus 2 at placement and does not deduct again at acceptance', function (): void {
    [$order, $product] = makeCustomerReservedOrder($this);
    expect($product->fresh()->stock_quantity)->toBe(8);

    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept");
    $response->assertOk();
    expect($product->fresh()->stock_quantity)->toBe(8);

    $this->assertDatabaseCount('sm_inventory_logs', 1);
    $this->assertDatabaseHas('sm_inventory_logs', [
        'reference_id' => $order->id,
        'product_id' => $product->id,
        'type' => SmInventoryLogType::OrderDeduction->value,
        'quantity_change' => -2,
    ]);
});

it('legacy already-reserved customer order cannot double-deduct at acceptance', function (): void {
    [$order, $product] = makeCustomerReservedOrder($this, legacy: true);
    expect($product->fresh()->stock_quantity)->toBe(8);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")->assertOk();
    expect($product->fresh()->stock_quantity)->toBe(8);
});

it('rejected reserved customer order restores stock once without issuing a refund', function (): void {
    [$order, $product] = makeCustomerReservedOrder($this);
    $payload = [
        'reason' => 'Unavailable item',
        'rejectionType' => 'other',
    ];
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload)->assertOk();
    expect($product->fresh()->stock_quantity)->toBe(10);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/reject", $payload)->assertStatus(400);
    expect($product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseHas('sm_inventory_logs', [
        'reference_id' => $order->id,
        'product_id' => $product->id,
        'type' => SmInventoryLogType::Return->value,
        'quantity_change' => 2,
    ]);
});

it('accepted customer order cancellation releases reserved stock only once', function (): void {
    [$order, $product] = makeCustomerReservedOrder($this);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")->assertOk();
    $service = app(\Modules\Supermarket\Services\SmOrderService::class);
    $service->cancelAfterAcceptance($order->fresh(), 'Customer unavailable', (int) $this->seller->id);
    expect($product->fresh()->stock_quantity)->toBe(10);
    $service->cancelAfterAcceptance($order->fresh(), 'Repeated request', (int) $this->seller->id);
    expect($product->fresh()->stock_quantity)->toBe(10);
});

it('inventory-only return never credits more than total ordered across repeated requests', function (): void {
    $product = SmProductFactory::new()->create([
        'store_id' => $this->store->id, 'stock_quantity' => 20,
    ]);
    $order = SmOrderFactory::new()->create(['store_id' => $this->store->id, 'status' => 'completed']);
    $item = SmOrderItemFactory::new()->create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3,
    ]);
    $payload = [
        'items' => [['order_item_id' => $item->id, 'quantity' => 2]],
        'reason' => 'Damaged',
    ];
    $response = $this->postJson("/api/v1/store-owner/orders/{$order->id}/return", $payload);
    $response->assertOk()->assertJsonPath('data.financial_refund_issued', false);
    expect($product->fresh()->stock_quantity)->toBe(22);

    $this->postJson("/api/v1/store-owner/orders/{$order->id}/return", $payload)->assertStatus(400);
    expect($product->fresh()->stock_quantity)->toBe(22);
});

it('manual pending order still deducts exactly once at acceptance', function (): void {
    $product = SmProductFactory::new()->create(['store_id' => $this->store->id, 'stock_quantity' => 10]);
    $order = SmOrderFactory::new()->create(['store_id' => $this->store->id, 'status' => 'pending']);
    SmOrderItemFactory::new()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")->assertOk();
    expect($product->fresh()->stock_quantity)->toBe(8);
});

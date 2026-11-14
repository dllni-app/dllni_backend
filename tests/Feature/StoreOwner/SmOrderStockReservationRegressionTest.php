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

it('does not expose stock return, reject or cancellation routes', function (): void {
    [$order, $product] = makeCustomerReservedOrder($this);
    expect($product->fresh()->stock_quantity)->toBe(8);

    foreach (['return', 'reject', 'cancel'] as $action) {
        $this->postJson("/api/v1/store-owner/orders/{$order->id}/{$action}", [
            'reason' => 'No return policy',
            'rejectionType' => 'other',
            'items' => [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 2]],
        ])->assertNotFound();
    }

    expect($product->fresh()->stock_quantity)->toBe(8);
    expect($order->fresh()->status->value)->toBe('pending');
});

it('defensive service guard forbids cancellation without increasing stock', function (): void {
    [$order, $product] = makeCustomerReservedOrder($this);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")->assertOk();

    expect(fn () => app(\Modules\Supermarket\Services\SmOrderService::class)
        ->cancelAfterAcceptance($order, 'No refunds', $this->seller->id))
        ->toThrow(\DomainException::class);
    expect($product->fresh()->stock_quantity)->toBe(8);
});

it('owner acceptance alone never deducts stock because only placement reserves it', function (): void {
    $product = SmProductFactory::new()->create(['store_id' => $this->store->id, 'stock_quantity' => 10]);
    $order = SmOrderFactory::new()->create(['store_id' => $this->store->id, 'status' => 'pending']);
    SmOrderItemFactory::new()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);
    $this->postJson("/api/v1/store-owner/orders/{$order->id}/accept")->assertOk();
    expect($product->fresh()->stock_quantity)->toBe(10);
});

<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Modules\Supermarket\Services\SmInventoryService;
use Modules\User\Services\UserOrderHubService;

uses(RefreshDatabase::class);

it('returns exact historical note, modifier snapshots and substitution to customer', function (): void {
    $customer = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    $product = SmProductFactory::new()->create(['store_id' => $store->id]);
    $substitute = SmProductFactory::new()->create(['store_id' => $store->id]);
    $order = SmOrderFactory::new()->create([
        'customer_id' => $customer->id, 'store_id' => $store->id,
    ]);
    SmOrderItemFactory::new()->create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'note' => 'بدون كيس', 'modifier_snapshot' => [
            ['id' => 77, 'name' => 'حجم كبير', 'price' => 2.5],
        ],
        'substitute_product_id' => $substitute->id,
    ]);

    $payload = app(UserOrderHubService::class)->show((int) $customer->id, 'supermarket', (int) $order->id);
    expect($payload['items'][0]['note'])->toBe('بدون كيس')
        ->and($payload['items'][0]['modifierSnapshot'][0]['name'])->toBe('حجم كبير')
        ->and($payload['items'][0]['substituteProductId'])->toBe($substitute->id)
        ->and($payload['items'][0]['substituteProduct']['name'])->toBe($substitute->name);
});

it('reorder carries line note and substitute into current cart for review', function (): void {
    $customer = User::factory()->create();
    $store = SmStoreFactory::new()->create(['is_active' => true]);
    $product = SmProductFactory::new()->create(['store_id' => $store->id, 'is_available' => true, 'stock_quantity' => 10]);
    $alternative = SmProductFactory::new()->create(['store_id' => $store->id, 'is_available' => true, 'stock_quantity' => 10]);
    $order = SmOrderFactory::new()->create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'completed']);
    SmOrderItemFactory::new()->create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2,
        'note' => 'بدون كيس', 'substitute_product_id' => $alternative->id,
        'modifier_snapshot' => [],
    ]);
    $hub = app(UserOrderHubService::class);
    $result = $hub->reorder((int) $customer->id, 'supermarket', (int) $order->id);
    expect($result['itemsAdded'])->toBe(1);
    $cart = app(\Modules\User\Services\UserSupermarketCartService::class)->list((int) $customer->id);
    expect($cart[0]['items'][0]['note'])->toBe('بدون كيس')
        ->and($cart[0]['items'][0]['substituteProductId'])->toBe($alternative->id);
});

it('customer cancellation is forbidden and already deducted stock stays deducted', function (): void {
    $customer = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    $product = SmProductFactory::new()->create(['store_id' => $store->id, 'stock_quantity' => 10]);
    $order = SmOrderFactory::new()->create([
        'customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'pending',
    ]);
    SmOrderItemFactory::new()->create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2,
    ]);
    app(SmInventoryService::class)->reserveStockForCustomerOrder($order, (int) $product->id, 2);

    expect(fn () => app(UserOrderHubService::class)
        ->cancel((int) $customer->id, 'supermarket', (int) $order->id, 'No refunds'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    expect($product->fresh()->stock_quantity)->toBe(8)
        ->and($order->fresh()->status->value)->toBe('pending');
});

it('customer pickup ETA uses actual store preparation estimate instead of fabricated 35', function (): void {
    $customer = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    Carbon::setTestNow('2026-11-14 10:00:00');
    try {
        $order = SmOrderFactory::new()->create([
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'status' => 'accepted',
            'estimated_preparation_minutes' => 20,
            'estimated_ready_at' => now()->addMinutes(20),
        ]);
        $result = app(UserOrderHubService::class)->tracking((int) $customer->id, 'supermarket', (int) $order->id);
        expect($result['eta']['minutes'])->toBe(20);
        $order->update(['estimated_preparation_minutes' => null, 'estimated_ready_at' => null]);
        $result = app(UserOrderHubService::class)->tracking((int) $customer->id, 'supermarket', (int) $order->id);
        expect($result['eta']['minutes'])->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

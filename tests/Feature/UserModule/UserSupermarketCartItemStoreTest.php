<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmOrder;

function makeAvailableSupermarketProductForStore($store, int $price = 10, int $stock = 50)
{
    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    return SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
        'stock_quantity' => $stock,
        'price' => $price,
        'discounted_price' => null,
    ]);
}

it('preserves items from multiple stores in the same supermarket cart and allows checkout', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstStore = SmStoreFactory::new()->create();
    $secondStore = SmStoreFactory::new()->create();
    $firstProduct = makeAvailableSupermarketProductForStore($firstStore, 14);
    $secondProduct = makeAvailableSupermarketProductForStore($secondStore, 19);

    $firstAddResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $firstProduct->id,
        'quantity' => 1,
    ])->assertCreated();

    $firstCartId = (int) $firstAddResponse->json('data.id');

    $secondAddResponse = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $secondProduct->id,
        'quantity' => 2,
    ])->assertCreated();

    expect($secondAddResponse->json('data.id'))->toBe($firstCartId);
    expect($secondAddResponse->json('data.merchantGroups'))->toHaveCount(2);
    expect($secondAddResponse->json('data.merchant'))->toBeNull();
    expect($secondAddResponse->json('data.isMultiMerchant'))->toBeTrue();
    expect($secondAddResponse->json('data.checkout.canPlaceOrder'))->toBeTrue();
    expect($secondAddResponse->json('data.checkout.blockedReason'))->toBeNull();

    $this->assertDatabaseCount('sm_carts', 1);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $firstProduct->id,
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('sm_cart_items', [
        'cart_id' => $firstCartId,
        'product_id' => $secondProduct->id,
        'quantity' => 2,
    ]);
});

it('merges the same supermarket product into one cart line with summed quantity', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create();
    $product = makeAvailableSupermarketProductForStore($store, 11, 20);

    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $response = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    expect($response->json('data.items'))->toHaveCount(1);
    expect($response->json('data.items.0.quantity'))->toBe(3);
    expect((float) $response->json('data.items.0.totalPrice'))->toBe(33.0);

    $this->assertDatabaseCount('sm_cart_items', 1);
    $this->assertDatabaseHas('sm_cart_items', [
        'product_id' => $product->id,
        'quantity' => 3,
    ]);
});

it('places a multi-merchant supermarket checkout as one batch with one order per store', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstStore = SmStoreFactory::new()->create();
    $secondStore = SmStoreFactory::new()->create();
    $firstProduct = makeAvailableSupermarketProductForStore($firstStore, 14);
    $secondProduct = makeAvailableSupermarketProductForStore($secondStore, 19);

    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $firstProduct->id,
        'quantity' => 1,
    ])->assertCreated();

    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $secondProduct->id,
        'quantity' => 2,
    ])->assertCreated();

    $response = $this->postJson('/api/v1/user/supermarket/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
    ])->assertCreated();

    expect($response->json('data.checkoutBatchNumber'))->not->toBeNull();
    expect($response->json('data.isMultiMerchant'))->toBeTrue();
    expect($response->json('data.createdOrdersCount'))->toBe(2);
    expect($response->json('data.orders'))->toHaveCount(2);
    expect((float) $response->json('data.amounts.subtotal'))->toBe(52.0);
    expect((float) $response->json('data.amounts.total'))->toBe(52.0);

    $batchNumber = $response->json('data.checkoutBatchNumber');

    $this->assertDatabaseCount('sm_orders', 2);
    $this->assertDatabaseCount('sm_order_items', 2);
    $this->assertDatabaseMissing('sm_carts', ['user_id' => $user->id]);

    $firstOrder = SmOrder::query()->where('store_id', $firstStore->id)->with('items')->firstOrFail();
    $secondOrder = SmOrder::query()->where('store_id', $secondStore->id)->with('items')->firstOrFail();

    expect($firstOrder->checkout_batch_number)->toBe($batchNumber);
    expect($secondOrder->checkout_batch_number)->toBe($batchNumber);
    expect((int) $firstOrder->checkout_orders_count)->toBe(2);
    expect((int) $secondOrder->checkout_orders_count)->toBe(2);

    expect($firstOrder->items)->toHaveCount(1);
    expect($firstOrder->items->first()->product_id)->toBe($firstProduct->id);
    expect((int) $firstOrder->items->first()->quantity)->toBe(1);

    expect($secondOrder->items)->toHaveCount(1);
    expect($secondOrder->items->first()->product_id)->toBe($secondProduct->id);
    expect((int) $secondOrder->items->first()->quantity)->toBe(2);
});

it('does not place checkout or delete the cart when an item is out of stock', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create();
    $product = makeAvailableSupermarketProductForStore($store, 10, 2);

    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $product->update(['stock_quantity' => 1]);

    $this->postJson('/api/v1/user/supermarket/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
    ])->assertUnprocessable();

    $this->assertDatabaseCount('sm_orders', 0);
    $this->assertDatabaseHas('sm_carts', ['user_id' => $user->id]);
});

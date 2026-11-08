<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

function commerceReceiptRestaurantCart(User $user): array
{
    $restaurant = Restaurant::factory()->create(['latitude' => 36.21, 'longitude' => 37.15]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'price' => 12000, 'discounted_price' => null]);
    $cart = Cart::query()->create(['user_id' => $user->id, 'restaurant_id' => $restaurant->id]);
    CartItem::query()->create([
        'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1,
        'unit_price' => 12000, 'total_price' => 12000, 'signature_hash' => 'restaurant-receipt-'.$cart->id,
    ]);

    return [$cart, $restaurant];
}

function commerceReceiptSupermarketCart(User $user): array
{
    $store = SmStore::factory()->create(['latitude' => 36.22, 'longitude' => 37.17]);
    $product = SmProduct::factory()->create([
        'store_id' => $store->id, 'price' => 9000, 'discounted_price' => null,
        'stock_quantity' => 9, 'is_available' => true,
    ]);
    $cart = SmCart::query()->create(['user_id' => $user->id, 'store_id' => $store->id]);
    SmCartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 9000]);

    return [$cart, $product];
}

function commerceReceiptAddress(User $user): UserAddress
{
    return UserAddress::factory()->create([
        'user_id' => $user->id, 'latitude' => 36.202104, 'longitude' => 37.134260,
    ]);
}

it('replays restaurant delivery checkout with the same idempotency key without a duplicate order or courier record', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    [$cart] = commerceReceiptRestaurantCart($user);
    $address = commerceReceiptAddress($user);
    $key = 'rest-checkout-retry-0001';
    $payload = ['fulfillmentType' => 'delivery', 'receiveMode' => 'immediate', 'addressId' => $address->id];
    $path = '/api/v1/user/restaurants/carts/'.$cart->id.'/orders';

    $created = $this->withHeader('Idempotency-Key', $key)->postJson($path, $payload)->assertCreated();
    $replayed = $this->withHeader('Idempotency-Key', $key)->postJson($path, $payload)->assertCreated();

    expect($replayed->json('data.id'))->toBe($created->json('data.id'))
        ->and(Order::query()->count())->toBe(1)
        ->and(DeliveryOrder::query()->count())->toBe(1)
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeFalse();

    $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=restaurant')
        ->assertOk()->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.orderId', $created->json('data.id'))
        ->assertJsonPath('data.section', 'restaurant');
});

it('does not change the submitted restaurant order when a reused key has different payload', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart] = commerceReceiptRestaurantCart($user);
    $path = '/api/v1/user/restaurants/carts/'.$cart->id.'/orders';
    $key = 'rest-checkout-conflict-01';
    $this->withHeader('Idempotency-Key', $key)->postJson($path, [
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
    ])->assertCreated();

    $this->withHeader('Idempotency-Key', $key)->postJson($path, [
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'note' => 'changed',
    ])->assertStatus(409);
    expect(Order::query()->count())->toBe(1);
});

it('replays supermarket delivery without consuming stock twice', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    [$cart, $product] = commerceReceiptSupermarketCart($user);
    $address = commerceReceiptAddress($user);
    $key = 'market-checkout-retry-001';
    $path = '/api/v1/user/supermarket/carts/'.$cart->id.'/orders';
    $payload = ['fulfillmentType' => 'delivery', 'receiveMode' => 'immediate', 'addressId' => $address->id];

    $first = $this->withHeader('Idempotency-Key', $key)->postJson($path, $payload)->assertCreated();
    $second = $this->withHeader('Idempotency-Key', $key)->postJson($path, $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(SmOrder::query()->count())->toBe(1)
        ->and(DeliveryOrder::query()->count())->toBe(1)
        ->and((int) $product->fresh()->stock_quantity)->toBe(7)
        ->and(DB::table('commerce_checkout_receipts')->count())->toBe(1);

    $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=supermarket')
        ->assertOk()->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.orderId', $first->json('data.id'));
});

it('does not expose another customers checkout receipt', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    [$cart] = commerceReceiptRestaurantCart($owner);
    $key = 'receipt-customer-scope-01';

    $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertCreated();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=restaurant')
        ->assertOk()->assertJsonPath('data.status', 'not_found')->assertJsonPath('data.orderId', null);
});

it('rejects malformed idempotency keys before creating an order', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart] = commerceReceiptRestaurantCart($user);
    $this->withHeader('Idempotency-Key', 'short')
        ->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertUnprocessable()->assertJsonValidationErrors(['idempotencyKey']);

    expect(Order::query()->count())->toBe(0)
        ->and(DB::table('commerce_checkout_receipts')->count())->toBe(0);
});

it('rolls back both the receipt and order if linked delivery fails', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart] = commerceReceiptRestaurantCart($user);
    $address = commerceReceiptAddress($user);
    $key = 'failed-delivery-receipt-01';
    $path = '/api/v1/user/restaurants/carts/'.$cart->id.'/orders';
    $payload = ['fulfillmentType' => 'delivery', 'receiveMode' => 'immediate', 'addressId' => $address->id];

    $this->withHeader('Idempotency-Key', $key)->postJson($path, $payload)->assertUnprocessable();

    expect(DB::table('commerce_checkout_receipts')->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0)
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $this->withHeader('Idempotency-Key', $key)->postJson($path, $payload)->assertCreated();
    expect(Order::query()->count())->toBe(1)
        ->and(DB::table('commerce_checkout_receipts')->count())->toBe(1);
});

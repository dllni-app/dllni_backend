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

function quoteRestaurantCart(User $user): array
{
    $restaurant = Restaurant::factory()->create(['latitude' => 36.21, 'longitude' => 37.15]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'price' => 12000, 'discounted_price' => null]);
    $cart = Cart::query()->create(['user_id' => $user->id, 'restaurant_id' => $restaurant->id]);
    $item = CartItem::query()->create([
        'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1,
        'unit_price' => 12000, 'total_price' => 12000, 'signature_hash' => 'quote-'.$cart->id,
    ]);

    return [$cart, $product, $item];
}

function quoteSupermarketCart(User $user): array
{
    $store = SmStore::factory()->create(['latitude' => 36.22, 'longitude' => 37.17]);
    $product = SmProduct::factory()->create(['store_id' => $store->id, 'price' => 9000, 'discounted_price' => null, 'stock_quantity' => 6, 'is_available' => true]);
    $cart = SmCart::query()->create(['user_id' => $user->id, 'store_id' => $store->id]);
    $item = SmCartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 9000]);

    return [$cart, $product, $item];
}

it('creates and consumes an authoritative restaurant pickup quote exactly once', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart] = quoteRestaurantCart($user);
    $selection = ['section' => 'restaurant', 'cartId' => $cart->id, 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];
    $quoted = $this->postJson('/api/v1/user/commerce/checkout/quotes', $selection)
        ->assertCreated()->assertJsonStructure(['data' => ['quoteId', 'expiresAt', 'amounts' => ['total']]]);
    $id = (string) $quoted->json('data.quoteId');
    $key = 'restaurant-quoted-0001';

    $first = $this->withHeader('Idempotency-Key', $key)->postJson(
        '/api/v1/user/restaurants/carts/'.$cart->id.'/orders',
        ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $id],
    )->assertCreated();
    $replayed = $this->withHeader('Idempotency-Key', $key)->postJson(
        '/api/v1/user/restaurants/carts/'.$cart->id.'/orders',
        ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $id],
    )->assertCreated();

    expect($replayed->json('data.id'))->toBe($first->json('data.id'))
        ->and(Order::query()->count())->toBe(1)
        ->and((int) DB::table('commerce_checkout_quotes')->where('id', $id)->value('consumed_order_id'))->toBe((int) $first->json('data.id'));
});

it('rejects restaurant quote after cart configuration or catalogue price changes', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart, $product, $item] = quoteRestaurantCart($user);
    $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
        'section' => 'restaurant', 'cartId' => $cart->id, 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
    ])->assertCreated();
    $id = $quote->json('data.quoteId');
    $path = '/api/v1/user/restaurants/carts/'.$cart->id.'/orders';
    $payload = ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $id];

    $item->update(['quantity' => 2, 'total_price' => 24000]);
    $this->postJson($path, $payload)->assertStatus(409);
    expect(Order::query()->count())->toBe(0);
    $item->update(['quantity' => 1, 'total_price' => 12000]);
    $product->update(['price' => 13000]);
    $this->postJson($path, $payload)->assertStatus(409);
    expect(Order::query()->count())->toBe(0)
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();
});

it('expires restaurant quotes without consuming a cart', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart] = quoteRestaurantCart($user);
    $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
        'section' => 'restaurant', 'cartId' => $cart->id, 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
    ])->assertCreated();
    $this->travel(6)->minutes();

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $quote->json('data.quoteId'),
    ])->assertStatus(409);
    expect(Order::query()->count())->toBe(0)
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();
});

it('creates a supermarket delivery order from a quote and does not decrement stock on replay', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    [$cart, $product] = quoteSupermarketCart($user);
    $address = UserAddress::factory()->create(['user_id' => $user->id, 'latitude' => 36.202104, 'longitude' => 37.134260]);
    $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
        'section' => 'supermarket', 'cartId' => $cart->id, 'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate', 'addressId' => $address->id,
    ])->assertCreated();

    $key = 'supermarket-quoted-0001';
    $payload = ['fulfillmentType' => 'delivery', 'receiveMode' => 'immediate',
        'addressId' => $address->id, 'quoteId' => $quote->json('data.quoteId')];
    $first = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', $payload)->assertCreated();
    $replayed = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', $payload)->assertCreated();

    expect($replayed->json('data.id'))->toBe($first->json('data.id'))
        ->and(SmOrder::query()->count())->toBe(1)
        ->and(DeliveryOrder::query()->count())->toBe(1)
        ->and((int) $product->fresh()->stock_quantity)->toBe(4);
});

it('rolls back a supermarket order when the quoted amount no longer matches placement', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    [$cart, $product] = quoteSupermarketCart($user);
    $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
        'section' => 'supermarket', 'cartId' => $cart->id,
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
    ])->assertCreated();

    $id = (string) $quote->json('data.quoteId');
    $tamperedAmounts = $quote->json('data.amounts');
    $tamperedAmounts['total'] += 10;
    DB::table('commerce_checkout_quotes')->where('id', $id)->update([
        'amounts' => json_encode($tamperedAmounts, JSON_THROW_ON_ERROR),
    ]);
    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $id,
    ])->assertStatus(409);

    expect(SmOrder::query()->count())->toBe(0)
        ->and(SmCart::query()->whereKey($cart->id)->exists())->toBeTrue()
        ->and((int) $product->fresh()->stock_quantity)->toBe(6)
        ->and(DB::table('commerce_checkout_quotes')->where('id', $id)->value('consumed_order_id'))->toBeNull();
});

it('does not allow another user to place an order using a foreign quote', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    [$ownerCart] = quoteRestaurantCart($owner);
    $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
        'section' => 'restaurant', 'cartId' => $ownerCart->id,
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
    ])->assertCreated();

    $attacker = User::factory()->create();
    Sanctum::actingAs($attacker);
    [$attackerCart] = quoteRestaurantCart($attacker);
    $this->postJson('/api/v1/user/restaurants/carts/'.$attackerCart->id.'/orders', [
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $quote->json('data.quoteId'),
    ])->assertStatus(409);
    expect(Order::query()->count())->toBe(0);
});

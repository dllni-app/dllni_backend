<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmProductFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

function linkedDeliveryAddress(User $user, array $overrides = []): UserAddress
{
    return UserAddress::factory()->create([
        'user_id' => $user->id,
        'latitude' => 36.202104,
        'longitude' => 37.134260,
        ...$overrides,
    ]);
}

function linkedRestaurantCart(User $user, array $restaurantOverrides = []): Cart
{
    $restaurant = Restaurant::factory()->create([
        'latitude' => 36.210000,
        'longitude' => 37.150000,
        ...$restaurantOverrides,
    ]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'price' => 12000]);
    $cart = Cart::query()->create(['user_id' => $user->id, 'restaurant_id' => $restaurant->id]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 12000,
        'total_price' => 12000,
        'signature_hash' => fake()->uuid(),
    ]);

    return $cart->fresh(['restaurant', 'items.product']);
}

function linkedSupermarketCart(User $user, array $storeOverrides = []): SmCart
{
    $store = SmStore::factory()->create([
        'latitude' => 36.220000,
        'longitude' => 37.170000,
        ...$storeOverrides,
    ]);
    $product = SmProductFactory::new()->create(['store_id' => $store->id, 'price' => 9000]);
    $cart = SmCart::query()->create(['user_id' => $user->id, 'store_id' => $store->id]);
    SmCartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 9000,
    ]);

    return $cart->fresh(['store', 'items.product.store']);
}

it('restaurant delivery checkout creates a linked delivery order', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = linkedDeliveryAddress($user);
    $cart = linkedRestaurantCart($user);

    Sanctum::actingAs($user);

    $preview = $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/checkout/preview', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertOk();

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated()->assertJsonPath('data.deliverySummary.enabled', true);

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeInstanceOf(DeliveryOrder::class)
        ->and($order->deliveryOrder->source_type)->toBe(DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER)
        ->and($order->deliveryOrder->status)->toBe(DeliveryOrderStatus::WaitingMerchantReady->value)
        ->and((int) $order->deliveryOrder->created_by_user_id)->toBe((int) $user->id);

    $deliveryFee = (float) $order->deliveryOrder->delivery_fee;
    expect($deliveryFee)->toBeGreaterThan(0.0)
        ->and((float) $preview->json('data.amounts.deliveryFee'))->toBe($deliveryFee)
        ->and((float) $preview->json('data.amounts.total'))->toBe((float) $order->total_amount)
        ->and((float) $order->total_amount)->toBe((float) $order->subtotal + $deliveryFee);

    Queue::assertNotPushed(DispatchDeliveryOrderJob::class);
});

it('restaurant delivery checkout prefers a company with dispatch-capable drivers', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $dispatchableCompany = DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    DeliveryDriver::factory()->available()->create([
        'company_id' => $dispatchableCompany->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $address = linkedDeliveryAddress($user);
    $cart = linkedRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated();

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();

    expect((int) $order->deliveryOrder?->company_id)->toBe((int) $dispatchableCompany->id);
});

it('restaurant pickup checkout does not create a delivery order', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $cart = linkedRestaurantCart($user);

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/checkout/preview', [
        'fulfillmentType' => 'pickup',
        'receiveMode' => 'immediate',
    ])->assertOk()->assertJsonPath('data.amounts.deliveryFee', 0);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'pickup',
        'receiveMode' => 'immediate',
    ])->assertCreated();

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeNull();
    expect((float) $order->total_amount)->toBe((float) $order->subtotal);
});

it('supermarket delivery checkout creates a linked delivery order and uses the selected address', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = linkedDeliveryAddress($user, ['mobile' => '0999999999']);
    $cart = linkedSupermarketCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated()->assertJsonPath('data.deliverySummary.enabled', true);

    $order = SmOrder::query()->where('customer_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeInstanceOf(DeliveryOrder::class)
        ->and($order->deliveryOrder->source_type)->toBe(DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER)
        ->and((float) $order->deliveryOrder->dropoff_latitude)->toBe((float) $address->latitude)
        ->and((float) $order->deliveryOrder->dropoff_longitude)->toBe((float) $address->longitude)
        ->and($order->deliveryOrder->customer_phone)->toBe('0999999999');
});

it('rejects restaurant delivery checkout when coordinates are missing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = linkedDeliveryAddress($user, ['latitude' => null, 'longitude' => null]);
    $cart = linkedRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery']);

    expect(Order::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects restaurant delivery checkout when merchant coordinates are missing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = linkedDeliveryAddress($user);
    $cart = linkedRestaurantCart($user, ['latitude' => null, 'longitude' => null]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery']);

    expect(Order::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('prefers the nearest delivery company when multiple dispatch-capable companies exist', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $farCompany = DeliveryCompany::factory()->create([
        'latitude' => 35.000000,
        'longitude' => 36.000000,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $nearCompany = DeliveryCompany::factory()->create([
        'latitude' => 36.211000,
        'longitude' => 37.151000,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    DeliveryDriver::factory()->available()->create(['company_id' => $farCompany->id]);
    DeliveryDriver::factory()->available()->create(['company_id' => $nearCompany->id]);
    $address = linkedDeliveryAddress($user);
    $cart = linkedRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated();

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect((int) $order->deliveryOrder?->company_id)->toBe((int) $nearCompany->id);
});

it('rolls back the restaurant order and retains its cart when no delivery company can be linked', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $address = linkedDeliveryAddress($user);
    $cart = linkedRestaurantCart($user);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery']);

    expect(Order::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DeliveryOrder::query()->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();
});

it('rolls back the supermarket stock and cart when no delivery company can be linked', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $address = linkedDeliveryAddress($user);
    $cart = linkedSupermarketCart($user);
    $product = $cart->items->first()->product;
    $initialStock = $product->stock_quantity;
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery']);

    expect(SmOrder::query()->where('customer_id', $user->id)->exists())->toBeFalse()
        ->and(DeliveryOrder::query()->exists())->toBeFalse()
        ->and(SmCart::query()->whereKey($cart->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock_quantity)->toBe($initialStock);
});

it('returns the original restaurant order for the same idempotency key after cart deletion', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $cart = linkedRestaurantCart($user);
    Sanctum::actingAs($user);
    $key = 'restaurant-idempotent-20261105-01';
    $url = '/api/v1/user/restaurants/carts/'.$cart->id.'/orders';
    $payload = ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];

    $first = $this->withHeader('Idempotency-Key', $key)->postJson($url, $payload)->assertCreated();
    $second = $this->withHeader('Idempotency-Key', $key)->postJson($url, $payload)->assertCreated();
    $this->assertSame($first->json('data.id'), $second->json('data.id'));
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('commerce_checkout_receipts', 1);
    $this->assertDatabaseMissing('carts', ['id' => $cart->id]);

    $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=restaurant')
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.orderId', $first->json('data.id'));

    $this->withHeader('Idempotency-Key', $key)->postJson($url, $payload + ['note' => 'altered'])
        ->assertStatus(409);
    $this->assertDatabaseCount('orders', 1);
});

it('deduplicates supermarket placement and applies stock decrement only once', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $cart = linkedSupermarketCart($user);
    $product = $cart->items->first()->product;
    $stock = $product->stock_quantity;
    Sanctum::actingAs($user);
    $key = 'supermarket-idempotent-20261105-01';
    $url = '/api/v1/user/supermarket/carts/'.$cart->id.'/orders';
    $payload = ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];

    $first = $this->withHeader('Idempotency-Key', $key)->postJson($url, $payload)->assertCreated();
    $second = $this->withHeader('Idempotency-Key', $key)->postJson($url, $payload)->assertCreated();
    $this->assertSame($first->json('data.id'), $second->json('data.id'));
    $this->assertDatabaseCount('sm_orders', 1);
    $this->assertDatabaseCount('commerce_checkout_receipts', 1);
    if ($stock !== null) {
        $this->assertSame((int) $stock - 2, (int) $product->fresh()->stock_quantity);
    }

    $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=supermarket')
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.orderId', $first->json('data.id'));
});

it('scopes submission receipt lookup to the authenticated customer', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $cart = linkedRestaurantCart($owner);
    $key = 'customer-scoped-20261105-01';
    Sanctum::actingAs($owner);
    $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertCreated();

    Sanctum::actingAs($other);
    $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=restaurant')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_found')
        ->assertJsonPath('data.orderId', null);
});

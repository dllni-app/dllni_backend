<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Models\DeliveryCompany;
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
use Modules\Supermarket\Models\SmProduct;
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
    $product = SmProduct::factory()->create(['store_id' => $store->id, 'price' => 9000]);
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

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated()->assertJsonPath('data.deliverySummary.enabled', true);

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeInstanceOf(DeliveryOrder::class)
        ->and($order->deliveryOrder->source_type)->toBe(DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER)
        ->and((int) $order->deliveryOrder->created_by_user_id)->toBe((int) $user->id);
});

it('restaurant pickup checkout does not create a delivery order', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $cart = linkedRestaurantCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'pickup',
        'receiveMode' => 'immediate',
    ])->assertCreated();

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    expect($order->deliveryOrder)->toBeNull();
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

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

function linkedDeliveryValidationAddress(User $user, array $overrides = []): UserAddress
{
    return UserAddress::factory()->create([
        'user_id' => $user->id,
        'latitude' => 36.202104,
        'longitude' => 37.134260,
        ...$overrides,
    ]);
}

function linkedDeliveryValidationSupermarketCart(User $user, array $storeOverrides = []): SmCart
{
    $store = SmStore::factory()->create([
        'latitude' => 36.220000,
        'longitude' => 37.170000,
        ...$storeOverrides,
    ]);
    $product = SmProduct::factory()->create(['store_id' => $store->id, 'price' => 9000]);
    $cart = SmCart::query()->create(['user_id' => $user->id, 'store_id' => $store->id]);
    SmCartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9000]);

    return $cart->fresh(['store', 'items.product.store']);
}

it('rejects supermarket delivery checkout when user address coordinates are missing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = linkedDeliveryValidationAddress($user, ['latitude' => null, 'longitude' => null]);
    $cart = linkedDeliveryValidationSupermarketCart($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery']);

    expect(SmOrder::query()->where('customer_id', $user->id)->exists())->toBeFalse();
});

it('rejects supermarket delivery checkout when merchant coordinates are missing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    DeliveryCompany::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $address = linkedDeliveryValidationAddress($user);
    $cart = linkedDeliveryValidationSupermarketCart($user, ['latitude' => null, 'longitude' => null]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery']);

    expect(SmOrder::query()->where('customer_id', $user->id)->exists())->toBeFalse();
});

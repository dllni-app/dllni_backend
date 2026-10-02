<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmModifier;
use Modules\Supermarket\Models\SmModifierGroup;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

it('quotes and stores supermarket delivery fee separately while refreshing stale prices', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    DeliveryCompany::factory()->create([
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $store = SmStore::factory()->create([
        'latitude' => 36.220000,
        'longitude' => 37.170000,
    ]);
    $product = SmProduct::factory()->create([
        'store_id' => $store->id,
        'price' => 9000,
        'stock_quantity' => 5,
        'is_available' => true,
    ]);
    $cart = SmCart::query()->create([
        'user_id' => $user->id,
        'store_id' => $store->id,
    ]);
    $item = SmCartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 100,
    ]);
    $address = UserAddress::factory()->create([
        'user_id' => $user->id,
        'latitude' => 36.202104,
        'longitude' => 37.134260,
    ]);
    $preview = $this->postJson(
        '/api/v1/user/supermarket/carts/'.$cart->id.'/checkout/preview',
        [
            'fulfillmentType' => 'delivery',
            'receiveMode' => 'immediate',
            'addressId' => $address->id,
        ],
    )->assertOk();

    expect((float) $preview->json('data.amounts.subtotal'))->toBe(18000.0)
        ->and((float) $preview->json('data.amounts.serviceFee'))->toBe(0.0)
        ->and((float) $preview->json('data.amounts.deliveryFee'))->toBeGreaterThan(0.0)
        ->and((float) $preview->json('data.amounts.total'))
        ->toBe(
            (float) $preview->json('data.amounts.subtotal')
            + (float) $preview->json('data.amounts.deliveryFee')
        );

    expect((float) $item->fresh()->unit_price)->toBe(9000.0);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'delivery',
        'receiveMode' => 'immediate',
        'addressId' => $address->id,
    ])->assertCreated();
    $order = SmOrder::query()
        ->where('customer_id', $user->id)
        ->firstOrFail();

    expect((float) $order->service_fee)->toBe(0.0)
        ->and((float) $order->delivery_fee)->toBeGreaterThan(0.0)
        ->and((float) $order->total_amount)
        ->toBe((float) $order->subtotal + (float) $order->delivery_fee)
        ->and((float) $order->deliveryOrder?->delivery_fee)
        ->toBe((float) $order->delivery_fee)
        ->and((int) $product->fresh()->stock_quantity)->toBe(3);
});

it('persists supermarket customizations as separate lines and snapshots them to orders', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create();
    $product = SmProduct::factory()->create([
        'store_id' => $store->id,
        'price' => 1000,
        'stock_quantity' => 2,
        'is_available' => true,
    ]);
    $substitute = SmProduct::factory()->create([
        'store_id' => $store->id,
        'price' => 900,
        'stock_quantity' => 5,
        'is_available' => true,
    ]);
    $group = SmModifierGroup::query()->create([
        'store_id' => $store->id,
        'name' => 'الحجم',
        'is_required' => true,
        'min_selections' => 1,
        'max_selections' => 1,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $firstModifier = SmModifier::query()->create([
        'modifier_group_id' => $group->id,
        'name' => 'كبير',
        'price' => 500,
        'sort_order' => 0,
        'is_available' => true,
    ]);
    $secondModifier = SmModifier::query()->create([
        'modifier_group_id' => $group->id,
        'name' => 'صغير',
        'price' => 200,
        'sort_order' => 1,
        'is_available' => true,
    ]);
    $group->products()->attach($product->id);

    $first = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$firstModifier->id],
        'substituteProductId' => $substitute->id,
        'note' => 'عبوة حديثة',
    ])->assertCreated();

    $cartId = (int) $first->json('data.id');

    $second = $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$secondModifier->id],
        'note' => 'بدون بديل',
    ])->assertCreated();

    expect($second->json('data.items'))->toHaveCount(2)
        ->and((float) $first->json('data.items.0.unitPrice'))->toBe(1500.0)
        ->and($first->json('data.items.0.substituteProductId'))->toBe($substitute->id)
        ->and($first->json('data.items.0.note'))->toBe('عبوة حديثة');
    $this->postJson('/api/v1/user/supermarket/cart/items', [
        'productId' => $product->id,
        'quantity' => 1,
        'modifierIds' => [$firstModifier->id],
        'substituteProductId' => $substitute->id,
        'note' => 'عبوة حديثة',
    ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);

    $this->postJson('/api/v1/user/supermarket/carts/'.$cartId.'/orders', [
        'fulfillmentType' => 'pickup',
        'receiveMode' => 'immediate',
    ])->assertCreated();

    $order = SmOrder::query()
        ->where('customer_id', $user->id)
        ->with('items')
        ->firstOrFail();

    expect((float) $order->delivery_fee)->toBe(0.0)
        ->and($order->items)->toHaveCount(2);

    $snapshotted = $order->items
        ->firstWhere('substitute_product_id', $substitute->id);
    expect($snapshotted)->not->toBeNull()
        ->and($snapshotted->modifier_snapshot)->toBe([[
            'id' => $firstModifier->id,
            'modifierGroupId' => $group->id,
            'groupName' => 'الحجم',
            'name' => 'كبير',
            'price' => 500,
        ]])
        ->and($snapshotted->note)->toBe('عبوة حديثة');
});

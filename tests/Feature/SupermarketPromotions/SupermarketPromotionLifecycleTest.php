<?php

declare(strict_types=1);

namespace Tests\Feature\SupermarketPromotions;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Tests\TestCase;

final class SupermarketPromotionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supermarket-promotions.enabled' => true]);
        Queue::fake();
        Notification::fake();
        $this->withHeader('X-Supermarket-Promotions', '1');
    }

    public function test_checkout_snapshots_retries_stock_and_reversal(): void
    {
        [$owner, , $product, , $offerId] = $this->fixture();
        $cart = $this->add($product, 3);
        self::assertEquals(200, $cart['amounts']['subtotal']);
        self::assertEquals(100, $cart['amounts']['promotionDiscount']);
        self::assertEquals(1, $cart['items'][0]['freeQuantity']);
        $selection = ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];
        $path = '/api/v1/user/supermarket/carts/'.$cart['id'].'/orders';
        $this->postJson($path, $selection)->assertConflict();
        $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', ['section' => 'supermarket', 'cartId' => $cart['id'], ...$selection])->assertCreated()->json('data.quoteId');
        $this->withHeader('Idempotency-Key', 'sm-promotion-test-0001');
        $orderId = $this->postJson($path, [...$selection, 'quoteId' => $quote])->assertCreated()->json('data.id');
        $this->postJson($path, [...$selection, 'quoteId' => $quote])->assertCreated()->assertJsonPath('data.id', $orderId);
        $order = SmOrder::findOrFail($orderId);
        self::assertEquals(200, $order->subtotal);
        self::assertEquals(100, $order->promotion_discount_amount);
        self::assertEquals(3, $order->items()->sum('quantity'));
        self::assertEquals(1, $order->items()->sum('reward_quantity'));
        self::assertEquals(200, $order->items()->sum('total_price'));
        self::assertEquals(27, $product->fresh()->stock_quantity);
        self::assertEquals(1, DB::table('sm_order_promotion_applications')->where('order_id', $orderId)->count());
        DB::table('sm_offers')->where('id', $offerId)->update(['name' => 'Changed']);
        self::assertSame('عرض تجريبي', $order->fresh()->promotion_snapshot['appliedPromotions'][0]['name']);
        $this->getJson('/api/v1/user/orders/supermarket/'.$orderId)->assertOk()->assertJsonPath('data.promotionDiscountAmount', 100);
        $order->update(['status' => 'cancelled']);
        self::assertSame('reversed', DB::table('sm_order_promotion_applications')->where('order_id', $orderId)->value('status'));
    }

    public function test_specific_claim_is_idempotent_and_never_becomes_paid_implicitly(): void
    {
        [, , $product, $reward, $offerId] = $this->fixture('specific_products');
        $cart = $this->add($product, 2);
        $path = '/api/v1/user/supermarket/carts/'.$cart['id'].'/promotions/'.$offerId.'/claim';
        $body = ['productId' => $reward->id, 'quantity' => 1, 'expectedRevision' => $cart['revision']];
        $claimed = $this->postJson($path, $body)->assertOk()->json('data');
        $this->postJson($path, $body)->assertOk()->assertJsonCount(2, 'data.items');
        self::assertEquals(200, $claimed['amounts']['total']);
        $qualifier = collect($claimed['items'])->firstWhere('productId', $product->id);
        $invalid = $this->deleteJson('/api/v1/user/supermarket/carts/'.$cart['id'].'/items/'.$qualifier['id'])->assertOk()->json('data');
        self::assertNotEmpty($invalid['invalidRewards']);
        self::assertEquals(0, $invalid['amounts']['total']);
        $this->postJson('/api/v1/user/commerce/checkout/quotes', ['section' => 'supermarket', 'cartId' => $cart['id'], 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'])->assertUnprocessable();
        $this->patchJson('/api/v1/user/supermarket/carts/'.$cart['id'].'/items/'.$invalid['items'][0]['id'], ['quantity' => 1, 'expectedRevision' => $invalid['revision'], 'convertRewardToOrdinary' => true])->assertOk()->assertJsonPath('data.amounts.total', 40);
    }

    public function test_partial_reward_repeat_cap_and_existing_discounts(): void
    {
        [, , $product, , $offerId] = $this->fixture('same_product', 50);
        DB::table('sm_offers')->where('id', $offerId)->update(['max_applications_per_order' => 1]);
        $cart = $this->add($product, 6);
        self::assertEquals(550, $cart['amounts']['total']);
        self::assertEquals(50, $cart['amounts']['promotionDiscount']);
        $product->update(['discounted_price' => 50]);
        $this->getJson('/api/v1/user/supermarket/carts/'.$cart['id'])->assertOk()->assertJsonPath('data.amounts.total', 300)->assertJsonPath('data.amounts.promotionDiscount', 0);
    }

    public function test_rule_changes_invalidate_quote_and_old_clients_cannot_edit_or_checkout(): void
    {
        [$owner, , $product, , $offerId] = $this->fixture();
        $cart = $this->add($product, 3);
        $selection = ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];
        $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', ['section' => 'supermarket', 'cartId' => $cart['id'], ...$selection])->assertCreated()->json('data.quoteId');
        DB::table('sm_offers')->where('id', $offerId)->update(['is_active' => false]);
        $this->postJson('/api/v1/user/supermarket/carts/'.$cart['id'].'/orders', [...$selection, 'quoteId' => $quote])->assertConflict();
        $this->withHeader('X-Supermarket-Promotions', '0');
        $this->postJson('/api/v1/user/supermarket/carts/'.$cart['id'].'/orders', $selection)->assertConflict();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/sm-offers')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/sm-offers/'.$offerId, ['name' => 'Old app'])->assertUnprocessable();
    }

    public function test_scheduled_visibility_and_owner_round_trip(): void
    {
        [$owner, , $product, $reward, $offerId, $payload] = $this->fixture('specific_products');
        Sanctum::actingAs($owner);
        $payload['showUpcoming'] = true;
        $payload['startsAt'] = now()->addHour()->toIso8601String();
        $this->putJson('/api/v1/sm-offers/'.$offerId, $payload)->assertOk()->assertJsonPath('data.ruleVersion', 2)->assertJsonPath('data.rewardProducts.0.id', $reward->id);
        $this->getJson('/api/v1/sm-offers/'.$offerId)->assertOk()->assertJsonPath('data.offerProducts.0.productId', $product->id);
        $this->getJson('/api/v1/user/supermarket/offers/'.$offerId)->assertNotFound();
        $this->getJson('/api/v1/user/supermarket/offers/'.$offerId.'?includeUpcoming=1')->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->withHeader('X-Supermarket-Promotions', '0')->getJson('/api/v1/user/supermarket/offers/'.$offerId.'?includeUpcoming=1')->assertNotFound();
    }

    public function test_atomic_offer_add_rolls_back_when_reward_stock_is_missing(): void
    {
        [, , $product, $reward, $offerId] = $this->fixture('specific_products');
        $reward->update(['stock_quantity' => 0]);
        $this->postJson('/api/v1/user/supermarket/cart/offers/'.$offerId, ['productId' => $product->id, 'quantity' => 2, 'ruleVersion' => 1, 'rewardProductId' => $reward->id])->assertUnprocessable();
        self::assertSame(0, DB::table('sm_cart_items')->count());
    }

    public function test_cheapest_reward_uses_mixed_products_and_unavailable_stock_blocks_quote(): void
    {
        [$owner, , $product, $cheap, $offerId, $payload] = $this->fixture('cheapest_eligible');
        $customer = auth()->user();
        Sanctum::actingAs($owner);
        $payload['offerProducts'][] = ['productId' => $cheap->id];
        $this->putJson('/api/v1/sm-offers/'.$offerId, $payload)->assertOk();
        Sanctum::actingAs($customer);
        $this->add($product, 2);
        $cart = $this->add($cheap, 1);
        self::assertEquals(200, $cart['amounts']['total']);
        self::assertEquals(40, $cart['amounts']['promotionDiscount']);
        $cheap->update(['stock_quantity' => 0]);
        $this->postJson('/api/v1/user/commerce/checkout/quotes', ['section' => 'supermarket', 'cartId' => $cart['id'], 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'])->assertUnprocessable();
    }

    public function test_full_size_rule_and_merged_quantity_limit(): void
    {
        [$owner, , $product, , $offerId, $payload] = $this->fixture();
        $customer = auth()->user();
        Sanctum::actingAs($owner);
        $payload['buyQuantity'] = 100;
        $payload['rewardQuantity'] = 100;
        $this->putJson('/api/v1/sm-offers/'.$offerId, $payload)->assertOk();
        $product->update(['stock_quantity' => 1000]);
        Sanctum::actingAs($customer);
        $cart = $this->postJson('/api/v1/user/supermarket/cart/offers/'.$offerId, ['productId' => $product->id, 'quantity' => 200, 'ruleVersion' => 2])->assertCreated()->json('data');
        self::assertEquals(10000, $cart['amounts']['total']);
        self::assertEquals(100, $cart['items'][0]['freeQuantity']);
        $this->postJson('/api/v1/user/supermarket/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertUnprocessable();
        self::assertEquals(200, DB::table('sm_cart_items')->where('cart_id', $cart['id'])->sum('quantity'));
    }

    public function test_atomic_add_cannot_silently_add_full_price_units_when_cap_is_reached(): void
    {
        [, , $product, , $offerId] = $this->fixture();
        DB::table('sm_offers')->where('id', $offerId)->update(['max_applications_per_order' => 1]);
        $cart = $this->postJson('/api/v1/user/supermarket/cart/offers/'.$offerId, ['productId' => $product->id, 'quantity' => 3, 'ruleVersion' => 1])->assertCreated()->json('data');
        $this->postJson('/api/v1/user/supermarket/cart/offers/'.$offerId, ['productId' => $product->id, 'quantity' => 3, 'ruleVersion' => 1])->assertUnprocessable();
        self::assertEquals(3, DB::table('sm_cart_items')->where('cart_id', $cart['id'])->sum('quantity'));
    }

    public function test_free_units_keep_paid_required_modifiers_and_decimal_precision(): void
    {
        [, $store, $product] = $this->fixture();
        $group = \Modules\Supermarket\Models\SmModifierGroup::create(['store_id' => $store->id, 'name' => 'Size', 'is_required' => true, 'is_active' => true, 'min_selections' => 1, 'max_selections' => 1]);
        $group->products()->attach($product->id);
        $modifier = \Modules\Supermarket\Models\SmModifier::create(['modifier_group_id' => $group->id, 'name' => 'Large', 'price' => 2.5, 'is_available' => true]);
        $this->postJson('/api/v1/user/supermarket/cart/items', ['productId' => $product->id, 'quantity' => 3])->assertUnprocessable();
        $cart = $this->postJson('/api/v1/user/supermarket/cart/items', ['productId' => $product->id, 'quantity' => 3, 'modifierIds' => [$modifier->id]])->assertCreated()->json('data');
        self::assertEquals(207.5, $cart['amounts']['total']);
        self::assertEquals(307.5, $cart['amounts']['originalSubtotal']);
        self::assertEquals(1, $cart['items'][0]['freeQuantity']);
    }

    public function test_a_previously_promoted_cart_cannot_checkout_expired_goods_or_an_unavailable_store(): void
    {
        [, $store, $product] = $this->fixture();
        $cart = $this->add($product, 3);
        $selection = ['section' => 'supermarket', 'cartId' => $cart['id'], 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];
        $product->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/v1/user/commerce/checkout/quotes', $selection)->assertUnprocessable();
        $product->update(['expires_at' => null]);
        $store->update(['is_temporarily_closed' => true]);
        $this->postJson('/api/v1/user/commerce/checkout/quotes', $selection)->assertUnprocessable();
    }

    public function test_owner_dates_preserve_offsets_and_invalid_scopes_are_rejected(): void
    {
        [$owner, , $product, , $offerId, $payload] = $this->fixture('specific_products');
        Sanctum::actingAs($owner);
        config(['app.timezone' => 'UTC']);
        $payload['startsAt'] = '2030-01-01T09:00:00+03:00';
        $payload['endsAt'] = '2030-01-01T12:00:00+03:00';
        $this->putJson('/api/v1/sm-offers/'.$offerId, $payload)->assertOk()->assertJsonPath('data.startsAtIso', '2030-01-01T06:00:00+00:00');
        $this->assertDatabaseHas('sm_offers', ['id' => $offerId, 'starts_at' => '2030-01-01 06:00:00', 'ends_at' => '2030-01-01 09:00:00']);
        $foreign = SmProduct::factory()->create();
        $this->putJson('/api/v1/sm-offers/'.$offerId, [...$payload, 'rewardProductIds' => [$foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('rewardProductIds.0');
        $this->putJson('/api/v1/sm-offers/'.$offerId, [...$payload, 'rewardProductIds' => [$product->id]])->assertUnprocessable()->assertJsonValidationErrors('rewardProductIds');
        config(['supermarket-promotions.enabled' => false]);
        $this->postJson('/api/v1/sm-offers', $payload)->assertUnprocessable()->assertJsonValidationErrors('promotionType');
    }

    private function fixture(string $mode = 'same_product', int $percent = 100): array
    {
        $owner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
        $store = SmStore::factory()->create(['owner_user_id' => $owner->id, 'is_active' => true, 'is_temporarily_closed' => false, 'suspension_until' => null]);
        $product = SmProduct::factory()->create(['store_id' => $store->id, 'price' => 100, 'discounted_price' => null, 'stock_quantity' => 30, 'is_available' => true, 'expires_at' => null]);
        $reward = SmProduct::factory()->create(['store_id' => $store->id, 'price' => 40, 'discounted_price' => null, 'stock_quantity' => 30, 'is_available' => true, 'expires_at' => null]);
        Sanctum::actingAs($owner);
        $payload = ['name' => 'عرض تجريبي', 'promotionType' => 'buy_x_get_y', 'buyQuantity' => 2, 'rewardQuantity' => 1,
            'rewardType' => $mode, 'rewardDiscountPercent' => $percent, 'isRepeatable' => true, 'isActive' => true,
            'startsAt' => now()->subHour()->toIso8601String(), 'endsAt' => now()->addDay()->toIso8601String(),
            'offerProducts' => [['productId' => $product->id]], ...($mode === 'specific_products' ? ['rewardProductIds' => [$reward->id]] : [])];
        $offerId = $this->postJson('/api/v1/sm-offers', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/sm-offers?perPage=1')->assertOk()->assertJsonPath('meta.advancedPromotionsEnabled', true);
        Sanctum::actingAs(User::factory()->create());

        return [$owner, $store, $product, $reward, $offerId, $payload];
    }

    private function add(SmProduct $product, int $quantity): array
    {
        return $this->postJson('/api/v1/user/supermarket/cart/items', ['productId' => $product->id, 'quantity' => $quantity])->assertCreated()->json('data');
    }
}

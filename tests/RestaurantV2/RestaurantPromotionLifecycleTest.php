<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

final class RestaurantPromotionLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        self::assertSame('alnadha_worker_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        config(['restaurant-promotions.enabled' => true]);
        Queue::fake();
        Notification::fake();
        $this->withHeader('X-Restaurant-Promotions', '1');
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['module_type' => UserModuleType::RestaurantSeller->value]);
        $restaurant = Restaurant::factory()->create(['user_id' => $owner->id, 'is_active' => true]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $meal = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'price' => 30000, 'discounted_price' => null, 'catalog_role' => 'independent', 'is_available' => true]);
        $drink = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'price' => 5000, 'discounted_price' => null, 'catalog_role' => 'independent', 'is_available' => true]);
        return [$owner, $restaurant, $meal, $drink];
    }

    private function createOffer(User $owner, Product $meal, ?Product $reward = null): Offer
    {
        Sanctum::actingAs($owner);
        $payload = ['name' => 'QA promotion', 'offerType' => 'buy_x_get_y', 'productIds' => [$meal->id],
            'buyQuantity' => 2, 'rewardQuantity' => 1, 'rewardType' => $reward ? 'specific_products' : 'same_product',
            'rewardDiscountPercent' => 100, 'isRepeatable' => true, 'isActive' => true,
            'startsAt' => now()->subHour()->toIso8601String(), 'endsAt' => now()->addHour()->toIso8601String()];
        if ($reward) $payload['rewardProductIds'] = [$reward->id];
        $result = $this->postJson('/api/v1/restaurant-owner/offers', $payload)->assertCreated();
        return Offer::query()->findOrFail($result->json('data.id'));
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        return $user;
    }

    private function add(Product $product, int $quantity, array $modifierIds = []): array
    {
        return $this->postJson('/api/v1/user/restaurants/cart/items', [
            'productId' => $product->id, 'quantity' => $quantity, 'modifierIds' => $modifierIds,
        ])->assertCreated()->json('data');
    }

    private function quote(int $cartId): string
    {
        return $this->postJson('/api/v1/user/commerce/checkout/quotes', [
            'section' => 'restaurant', 'cartId' => $cartId, 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertCreated()->json('data.quoteId');
    }

    public function test_same_product_checkout_snapshots_replay_and_reversal(): void
    {
        [$owner, $restaurant, $meal] = $this->fixture();
        $offer = $this->createOffer($owner, $meal);
        $group = ModifierGroup::create(['restaurant_id' => $restaurant->id, 'name' => 'QA extras',
            'is_required' => false, 'min_selections' => 0, 'max_selections' => 1]);
        $group->products()->attach($meal->id);
        $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'QA cheese', 'price' => 3000, 'sort_order' => 1]);
        $user = $this->customer();
        $cart = $this->add($meal, 3, [$modifier->id]);
        self::assertEquals(69000, $cart['amounts']['total']);
        self::assertEquals(99000, $cart['amounts']['originalSubtotal']);
        self::assertEquals(30000, $cart['amounts']['promotionDiscount']);
        $path = '/api/v1/user/restaurants/carts/'.$cart['id'].'/orders';
        $selection = ['fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'];
        $this->postJson($path, $selection)->assertConflict();
        $quote = $this->quote($cart['id']);
        $this->withHeader('Idempotency-Key', 'qa-promotion-'.uniqid());
        $body = [...$selection, 'quoteId' => $quote];
        $id = $this->postJson($path, $body)->assertCreated()->json('data.id');
        $this->postJson($path, $body)->assertCreated()->assertJsonPath('data.id', $id);
        $order = Order::query()->findOrFail($id);
        self::assertEquals(69000, $order->subtotal);
        self::assertEquals(69000, $order->total_amount);
        self::assertEquals(69000, $order->merchant_gross_amount);
        self::assertEquals(30000, $order->promotion_discount_amount);
        self::assertEquals(3, $order->orderItems()->sum('quantity'));
        self::assertEquals(1, $order->orderItems()->sum('reward_quantity'));
        self::assertEquals(69000, $order->orderItems()->sum('total_price'));
        self::assertEquals(3000, $order->orderItems()->where('reward_quantity', 1)->value('unit_price'));
        self::assertEquals(1, $order->promotionApplications()->count());
        $this->assertDatabaseMissing('carts', ['id' => $cart['id']]);
        $this->getJson('/api/v1/user/orders/restaurant/'.$id)->assertOk()->assertJsonPath('data.promotionDiscountAmount', 30000)->assertJsonPath('data.appliedPromotions.0.name', 'QA promotion');
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/restaurant-owner/orders/'.$id)->assertOk()->assertJsonPath('data.canEditItems', false)->assertJsonPath('data.promotionDiscountAmount', 30000);
        $offer->update(['name' => 'changed']);
        self::assertSame('QA promotion', $order->fresh()->promotion_snapshot['appliedPromotions'][0]['name']);
        $order->update(['status' => 'cancelled', 'cancellation_reason' => 'QA rollback']);
        self::assertSame('reversed', $order->promotionApplications()->firstOrFail()->status);
    }

    public function test_specific_reward_claim_is_idempotent_and_invalid_after_removing_qualifier(): void
    {
        [$owner, , $meal, $drink] = $this->fixture();
        $offer = $this->createOffer($owner, $meal, $drink);
        $this->customer();
        $cart = $this->add($meal, 2);
        $claimPath = '/api/v1/user/restaurants/carts/'.$cart['id'].'/promotions/'.$offer->id.'/claim';
        $claim = ['productId' => $drink->id, 'quantity' => 1, 'expectedRevision' => $cart['revision']];
        $claimed = $this->postJson($claimPath, $claim)->assertOk()->json('data');
        self::assertEquals(60000, $claimed['amounts']['total']);
        self::assertEquals(5000, $claimed['amounts']['promotionDiscount']);
        $retry = $this->postJson($claimPath, $claim)->assertOk()->json('data');
        self::assertCount(2, $retry['items']);
        $qualifier = collect($retry['items'])->firstWhere('productId', $meal->id);
        $updated = $this->deleteJson('/api/v1/user/restaurants/carts/'.$cart['id'].'/items/'.$qualifier['id'])->assertOk()->json('data');
        self::assertNotEmpty($updated['invalidRewards']);
        $this->postJson('/api/v1/user/commerce/checkout/quotes', [
            'section' => 'restaurant', 'cartId' => $cart['id'], 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertUnprocessable();
        $item = $updated['items'][0];
        $converted = $this->patchJson('/api/v1/user/restaurants/carts/'.$cart['id'].'/items/'.$item['id'], [
            'quantity' => 1, 'convertRewardToOrdinary' => true, 'expectedRevision' => $updated['revision'],
        ])->assertOk()->json('data');
        self::assertEquals(5000, $converted['amounts']['total']);
        self::assertEmpty($converted['invalidRewards']);
    }

    public function test_rule_change_invalidates_quote(): void
    {
        [$owner, , $meal] = $this->fixture();
        $offer = $this->createOffer($owner, $meal);
        $this->customer();
        $cart = $this->add($meal, 3);
        $quote = $this->quote($cart['id']);
        $offer->update(['is_active' => false]);
        $this->withHeader('Idempotency-Key', 'qa-stale-'.uniqid())->postJson('/api/v1/user/restaurants/carts/'.$cart['id'].'/orders', [
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $quote,
        ])->assertConflict();
        $this->assertDatabaseHas('carts', ['id' => $cart['id']]);
        $this->assertDatabaseHas('commerce_checkout_quotes', ['id' => $quote, 'consumed_order_id' => null]);
    }

    public function test_public_catalog_and_owner_list_expose_only_eligible_advanced_rules(): void
    {
        [$owner, $restaurant, $meal, $drink] = $this->fixture();
        $offer = $this->createOffer($owner, $meal, $drink);
        $this->getJson('/api/v2/user/restaurants/offers/'.$offer->id)->assertOk()
            ->assertJsonPath('data.offerType', 'buy_x_get_y')
            ->assertJsonPath('data.rewardProducts.0.id', $drink->id);
        $this->getJson('/api/v2/user/restaurants/products/'.$meal->id)->assertOk()
            ->assertJsonPath('data.promotions.0.id', $offer->id)
            ->assertJsonPath('data.price', '30000.00');
        $this->getJson('/api/v2/user/restaurants/'.$restaurant->id)->assertOk()
            ->assertJsonPath('data.offers.0.id', $offer->id);
        $this->getJson('/api/v1/restaurant-owner/offers')->assertOk()
            ->assertJsonPath('data.0.rewardProducts.0.id', $drink->id)
            ->assertJsonPath('data.0.performance.ordersCount', 0);
        $this->getJson('/api/v1/restaurant-owner/offers/summary')->assertOk()
            ->assertJsonPath('summary.totalUsageOrders', 0);
        $this->withHeader('X-Restaurant-Promotions', '0');
        $this->getJson('/api/v2/user/restaurants/offers/'.$offer->id)->assertNotFound();
        $this->getJson('/api/v2/user/restaurants/products/'.$meal->id)->assertOk()->assertJsonCount(0, 'data.promotions');
        $this->getJson('/api/v1/restaurant-owner/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeader('X-Restaurant-Promotions', '1');
        $drink->update(['is_available' => false, 'unavailable_until' => null]);
        $this->getJson('/api/v2/user/restaurants/offers/'.$offer->id)->assertNotFound();
        $this->getJson('/api/v2/user/restaurants/products/'.$meal->id)->assertOk()->assertJsonCount(0, 'data.promotions');
        $this->getJson('/api/v2/user/restaurants/discovery?filter[hasOffers]=1')->assertOk();
    }

    public function test_product_discount_requires_available_products_and_disappears_when_none_remain(): void
    {
        [$owner, $restaurant, $meal, $drink] = $this->fixture();
        Sanctum::actingAs($owner);
        $path = '/api/v1/restaurant-owner/offers';
        $payload = ['name' => 'QA ordinary discount', 'offerType' => 'product_discount',
            'discountType' => 'percentage', 'discountValue' => 15, 'isActive' => true,
            'startsAt' => now()->subHour()->toIso8601String(), 'endsAt' => now()->addHour()->toIso8601String()];
        $this->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors('productIds');
        $this->postJson($path, [...$payload, 'productIds' => []])->assertUnprocessable()->assertJsonValidationErrors('productIds');
        $drink->update(['is_available' => false, 'unavailable_until' => null]);
        $this->postJson($path, [...$payload, 'productIds' => [$drink->id]])->assertUnprocessable()->assertJsonValidationErrors('productIds.0');
        $id = $this->postJson($path, [...$payload, 'productIds' => [$meal->id]])->assertCreated()->json('data.id');
        $this->putJson($path.'/'.$id, [...$payload, 'productIds' => []])->assertUnprocessable()->assertJsonValidationErrors('productIds');
        $this->getJson('/api/v2/user/restaurants/offers/'.$id)->assertOk()
            ->assertJsonPath('data.products.0.id', $meal->id)
            ->assertJsonPath('data.products.0.offerPrice', '25500.00');
        $meal->update(['is_available' => false, 'unavailable_until' => null]);
        $this->getJson('/api/v2/user/restaurants/offers/'.$id)->assertNotFound();
        $this->getJson('/api/v2/user/restaurants/'.$restaurant->id)->assertOk()->assertJsonCount(0, 'data.offers');
        $this->getJson('/api/v2/user/restaurants/home/promotions?perPage=20')->assertOk()
            ->assertJsonMissing(['id' => $id, 'title' => 'QA ordinary discount']);
        $meal->update(['is_available' => true]);
        $this->getJson('/api/v2/user/restaurants/offers/'.$id)->assertOk();
    }


    public function test_scheduled_publication_modes_and_price_boundaries(): void
    {
        [$owner, $restaurant, $meal, $drink] = $this->fixture();
        $now = now()->startOfSecond();
        $this->travelTo($now);
        Sanctum::actingAs($owner);
        $path = '/api/v1/restaurant-owner/offers';
        $payload = ['name' => 'QA upcoming discount', 'offerType' => 'product_discount',
            'discountType' => 'percentage', 'discountValue' => 15, 'isActive' => true,
            'startsAt' => $now->copy()->addMinutes(2)->toIso8601String(),
            'endsAt' => $now->copy()->addHour()->toIso8601String(),
            'productIds' => [$meal->id]];
        $visible = $this->postJson($path, [...$payload, 'showUpcoming' => true])->assertCreated()
            ->assertJsonPath('data.showUpcoming', true)->assertJsonPath('data.status', 'scheduled')->json('data.id');
        $hidden = $this->postJson($path, [...$payload, 'name' => 'QA hidden offer', 'productIds' => [$drink->id], 'showUpcoming' => false])
            ->assertCreated()->assertJsonPath('data.showUpcoming', false)->json('data.id');
        $this->getJson($path.'/'.$visible)->assertOk()->assertJsonPath('data.showUpcoming', true);
        $this->putJson($path.'/'.$visible, [...$payload, 'showUpcoming' => false])->assertOk()->assertJsonPath('data.showUpcoming', false);
        $this->getJson('/api/v2/user/restaurants/offers/'.$visible.'?includeUpcoming=1')->assertNotFound();
        $this->putJson($path.'/'.$visible, [...$payload, 'showUpcoming' => true])->assertOk()->assertJsonPath('data.showUpcoming', true);
        $this->putJson($path.'/'.$visible, $payload)->assertOk()->assertJsonPath('data.showUpcoming', true);
        $this->postJson($path, [...$payload, 'showUpcoming' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('showUpcoming');

        $this->customer();
        $public = '/api/v2/user/restaurants';
        $home = $this->getJson($public.'/home/promotions?includeUpcoming=1&perPage=20')->assertOk();
        $active = collect($home->json('data.items'))->pluck('id');
        $upcoming = collect($home->json('data.upcomingItems'))->pluck('id');
        self::assertFalse($active->contains($visible));
        self::assertFalse($active->contains($hidden));
        self::assertTrue($upcoming->contains($visible));
        self::assertFalse($upcoming->contains($hidden));
        self::assertNotNull($home->json('data.nextRefreshAt'));
        $legacy = $this->getJson($public.'/home/promotions?perPage=20')->assertOk();
        self::assertNull($legacy->json('data.upcomingItems'));
        $upcomingList = $this->getJson($public.'/offers?status=upcoming&includeSchedule=1')->assertOk();
        self::assertTrue(collect($upcomingList->json('data'))->pluck('id')->contains($visible));
        self::assertFalse(collect($upcomingList->json('data'))->pluck('id')->contains($hidden));
        $this->getJson($public.'/offers/'.$visible)->assertNotFound();
        $this->getJson($public.'/offers/'.$visible.'?includeUpcoming=1')->assertOk()
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.products.0.offerPrice', '25500.00');
        $this->getJson($public.'/offers/'.$hidden.'?includeUpcoming=1')->assertNotFound();
        self::assertEquals(30000, $meal->fresh()->effectivePrice());
        self::assertEquals(5000, $drink->fresh()->effectivePrice());

        $this->travelTo($now->copy()->addMinutes(2));
        $this->getJson($public.'/offers/'.$visible)->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson($public.'/offers/'.$hidden)->assertOk()->assertJsonPath('data.status', 'active');
        $upcomingList = $this->getJson($public.'/offers?status=upcoming')->assertOk();
        self::assertFalse(collect($upcomingList->json('data'))->pluck('id')->contains($visible));
        $activeList = $this->getJson($public.'/offers?status=active&perPage=50')->assertOk();
        self::assertTrue(collect($activeList->json('data'))->pluck('id')->contains($visible));
        self::assertTrue(collect($activeList->json('data'))->pluck('id')->contains($hidden));
        self::assertEquals(25500, $meal->fresh()->effectivePrice());
        self::assertEquals(4250, $drink->fresh()->effectivePrice());
        $this->travelTo($now->copy()->addHour());
        $this->getJson($public.'/offers/'.$visible.'?includeUpcoming=1')->assertNotFound();
        self::assertEquals(30000, $meal->fresh()->effectivePrice());
        $this->travelBack();
    }

    public function test_scheduled_advanced_offer_is_preview_only_until_start(): void
    {
        [$owner, $restaurant, $meal] = $this->fixture();
        Sanctum::actingAs($owner);
        $now = now()->startOfSecond();
        $this->travelTo($now);
        $payload = ['name' => 'QA scheduled advanced', 'offerType' => 'buy_x_get_y',
            'productIds' => [$meal->id], 'buyQuantity' => 2, 'rewardQuantity' => 1,
            'rewardType' => 'same_product', 'rewardDiscountPercent' => 100,
            'isRepeatable' => true, 'isActive' => true, 'showUpcoming' => true,
            'startsAt' => $now->copy()->addMinutes(2)->toIso8601String(),
            'endsAt' => $now->copy()->addHour()->toIso8601String()];
        $id = $this->postJson('/api/v1/restaurant-owner/offers', $payload)->assertCreated()->json('data.id');
        $this->customer();
        $this->getJson('/api/v2/user/restaurants/offers/'.$id.'?includeUpcoming=1')->assertOk()
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.buyQuantity', 2);
        $cart = $this->add($meal, 3);
        self::assertEquals(90000, $cart['amounts']['total']);
        self::assertEquals(0, $cart['amounts']['promotionDiscount']);
        $this->travelTo($now->copy()->addMinutes(2));
        $this->getJson('/api/v2/user/restaurants/offers/'.$id)->assertOk()->assertJsonPath('data.status', 'active');
        $this->travelBack();
    }

}
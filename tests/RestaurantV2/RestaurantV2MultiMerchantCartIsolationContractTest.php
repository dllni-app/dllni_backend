<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

/** Rollback-contained real MySQL test: never creates persistent QA carts or orders. */
final class RestaurantV2MultiMerchantCartIsolationContractTest extends TestCase
{
    private User $buyer;
    private User $otherBuyer;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        $actual = DB::selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port');
        self::assertSame('alnadha_worker_test', $actual->db);
        self::assertSame(mb_strtolower((string) getenv('COMPUTERNAME')), mb_strtolower($actual->host));
        self::assertSame(3306, (int) $actual->port);
        DB::beginTransaction();
        Queue::fake();

        $this->buyer = User::factory()->create(['email' => 'rv2-f07-buyer-'.Str::uuid().'@example.test']);
        $this->otherBuyer = User::factory()->create(['email' => 'rv2-f07-other-'.Str::uuid().'@example.test']);
        $merchantA = Restaurant::factory()->create(['user_id' => $this->buyer->id, 'is_active' => true]);
        $merchantB = Restaurant::factory()->create(['user_id' => $this->buyer->id, 'is_active' => true]);
        $this->productA = Product::factory()->create(['restaurant_id' => $merchantA->id, 'price' => 110, 'discounted_price' => null, 'is_available' => true]);
        $this->productB = Product::factory()->create(['restaurant_id' => $merchantB->id, 'price' => 240, 'discounted_price' => null, 'is_available' => true]);
        Sanctum::actingAs($this->buyer);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    /** @return array{int, int, int, int} */
    private function twoCarts(): array
    {
        $first = $this->postJson('/api/v1/user/restaurants/cart/items', ['productId' => $this->productA->id, 'quantity' => 1])->assertCreated();
        $second = $this->postJson('/api/v1/user/restaurants/cart/items', ['productId' => $this->productB->id, 'quantity' => 2])->assertCreated();

        return [(int) $first->json('cartId'), (int) $first->json('itemId'), (int) $second->json('cartId'), (int) $second->json('itemId')];
    }

    public function test_two_merchant_carts_remain_independent_under_revision_and_cross_cart_mutations(): void
    {
        [$cartA, $itemA, $cartB, $itemB] = $this->twoCarts();
        self::assertNotSame($cartA, $cartB);
        $list = $this->getJson('/api/v1/user/restaurants/carts')->assertOk()->json('data');
        self::assertCount(2, $list);

        $revisionA = (int) $this->getJson('/api/v1/user/restaurants/carts/'.$cartA)->assertOk()->json('data.revision');
        $revisionB = (int) $this->getJson('/api/v1/user/restaurants/carts/'.$cartB)->assertOk()->json('data.revision');

        $this->patchJson("/api/v1/user/restaurants/carts/{$cartA}/items/{$itemB}", ['quantity' => 3, 'expectedRevision' => $revisionA])->assertNotFound();
        $this->deleteJson("/api/v1/user/restaurants/carts/{$cartB}/items/{$itemA}", ['expectedRevision' => $revisionB])->assertNotFound();

        $this->patchJson("/api/v1/user/restaurants/carts/{$cartA}/items/{$itemA}", ['quantity' => 3, 'expectedRevision' => $revisionA])->assertOk();
        $this->patchJson("/api/v1/user/restaurants/carts/{$cartA}/items/{$itemA}", ['quantity' => 4, 'expectedRevision' => $revisionA])->assertStatus(409);
        $this->getJson('/api/v1/user/restaurants/carts/'.$cartB)->assertOk()
            ->assertJsonPath('data.revision', $revisionB)
            ->assertJsonPath('data.items.0.productId', $this->productB->id)
            ->assertJsonPath('data.items.0.quantity', 2);

        Sanctum::actingAs($this->otherBuyer);
        $this->getJson('/api/v1/user/restaurants/carts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/user/restaurants/carts/'.$cartA)->assertNotFound();
        $this->patchJson("/api/v1/user/restaurants/carts/{$cartB}/items/{$itemB}", ['quantity' => 9, 'expectedRevision' => $revisionB])->assertNotFound();
        $this->postJson('/api/v1/user/commerce/checkout/quotes', ['section' => 'restaurant', 'cartId' => $cartA, 'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'])->assertNotFound();
    }

    public function test_absolute_quantity_and_global_unit_badge_preserve_other_configurations(): void
    {
        $path = '/api/v1/user/restaurants/cart/items';
        $countPath = '/api/v1/user/restaurants/cart/products-count';
        $payload = ['productId' => $this->productA->id, 'quantityMode' => 'set', 'note' => 'plain'];
        $first = $this->postJson($path, [...$payload, 'quantity' => 2])->assertCreated();
        $cartId = (int) $first->json('cartId');
        $itemId = (int) $first->json('itemId');
        $revision = (int) $first->json('data.revision');
        $this->getJson($countPath)->assertOk()->assertJsonPath('productsCount', 2);
        $second = $this->postJson($path, [...$payload, 'quantity' => 4, 'expectedRevision' => $revision])->assertOk();
        $second->assertJsonPath('itemId', $itemId)->assertJsonPath('quantity', 4);
        $this->getJson($countPath)->assertOk()->assertJsonPath('productsCount', 4);
        $this->postJson($path, [...$payload, 'quantity' => 9, 'expectedRevision' => $revision])->assertConflict();
        $third = $this->postJson($path, [...$payload, 'quantity' => 3, 'expectedRevision' => (int) $second->json('data.revision')])->assertOk();
        $third->assertJsonPath('itemId', $itemId)->assertJsonPath('quantity', 3);
        $this->getJson($countPath)->assertOk()->assertJsonPath('productsCount', 3);
        $this->postJson($path, [...$payload, 'quantity' => 1, 'note' => 'another configuration'])->assertCreated();
        $this->postJson($path, ['productId' => $this->productB->id, 'quantity' => 2])->assertCreated();
        $this->getJson($countPath)->assertOk()->assertJsonPath('productsCount', 6);
        $this->getJson('/api/v1/user/restaurants/carts/'.$cartId)->assertOk()->assertJsonCount(2, 'data.items');
        $this->deleteJson('/api/v1/user/restaurants/carts/'.$cartId.'/items/'.$itemId)->assertOk();
        $this->getJson($countPath)->assertOk()->assertJsonPath('productsCount', 3);
        Sanctum::actingAs($this->otherBuyer);
        $this->getJson($countPath)->assertOk()->assertJsonPath('productsCount', 0);
    }

    public function test_checkout_of_selected_cart_consumes_only_its_merchant_and_owned_order(): void
    {
        [$cartA, , $cartB] = $this->twoCarts();
        $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
            'section' => 'restaurant', 'cartId' => $cartA,
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertCreated()->json('data');

        $placed = $this->withHeader('Idempotency-Key', 'f07-'.Str::uuid())
            ->postJson('/api/v1/user/restaurants/carts/'.$cartA.'/orders', [
                'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate', 'quoteId' => $quote['quoteId'],
            ])->assertCreated();
        $orderId = (int) $placed->json('data.id');
        $order = Order::query()->with('orderItems')->findOrFail($orderId);
        self::assertSame((int) $this->buyer->id, (int) $order->user_id);
        self::assertSame((int) $this->productA->restaurant_id, (int) $order->restaurant_id);
        self::assertCount(1, $order->orderItems);
        self::assertSame((int) $this->productA->id, (int) $order->orderItems->first()->product_id);
        self::assertFalse(Cart::query()->whereKey($cartA)->exists());
        self::assertTrue(Cart::query()->whereKey($cartB)->exists());
        $this->getJson('/api/v1/user/restaurants/carts/'.$cartB)->assertOk()
            ->assertJsonPath('data.items.0.productId', $this->productB->id);

        Sanctum::actingAs($this->otherBuyer);
        $this->getJson('/api/v1/user/orders/restaurant/'.$orderId)->assertNotFound();
        $this->postJson('/api/v1/user/restaurants/carts/'.$cartB.'/orders', [
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertNotFound();
        self::assertTrue(Cart::query()->whereKey($cartB)->exists());
    }
}

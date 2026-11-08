<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

/** Local QA-only, rollback-scoped test of the real checkout/receipt endpoints. */
final class RestaurantV2CheckoutLifecycleContractTest extends TestCase
{
    private User $customer;

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
        $this->customer = User::factory()->create(['email' => 'rv2-checkout-'.Str::uuid().'@example.test']);
        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    /** @return array{Cart, Product} */
    private function preparedCart(): array
    {
        $merchant = Restaurant::factory()->create([
            'user_id' => $this->customer->id,
            'is_active' => true,
            'latitude' => 36.21,
            'longitude' => 37.15,
        ]);
        $product = Product::factory()->create([
            'restaurant_id' => $merchant->id,
            'price' => 12000,
            'discounted_price' => null,
            'is_available' => true,
        ]);
        $cart = Cart::query()->create([
            'user_id' => $this->customer->id,
            'restaurant_id' => $merchant->id,
        ]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 12000,
            'total_price' => 24000,
            'signature_hash' => 'rv2-live-contract-'.Str::uuid(),
        ]);
        return [$cart, $product];
    }

    public function test_quote_placement_receipt_and_replay_share_one_server_authoritative_order(): void
    {
        [$cart] = $this->preparedCart();
        $selection = [
            'section' => 'restaurant',
            'cartId' => $cart->id,
            'fulfillmentType' => 'pickup',
            'receiveMode' => 'immediate',
        ];
        $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', $selection)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['quoteId', 'amounts' => ['total']]])
            ->json('data');

        $key = 'rv2-quote-'.Str::uuid();
        $placeUrl = '/api/v1/user/restaurants/carts/'.$cart->id.'/orders';
        $payload = [
            'fulfillmentType' => 'pickup',
            'receiveMode' => 'immediate',
            'quoteId' => $quote['quoteId'],
        ];
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson($placeUrl, $payload)->assertCreated();
        $orderId = (int) $first->json('data.id');
        self::assertGreaterThan(0, $orderId);
        self::assertEqualsWithDelta((float) $quote['amounts']['total'], (float) $first->json('data.totalAmount'), 0.01);

        $this->getJson('/api/v1/user/commerce/submissions/'.$key.'?section=restaurant')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.orderId', $orderId);
        $this->getJson('/api/v1/user/orders/restaurant/'.$orderId)
            ->assertOk()->assertJsonPath('data.id', $orderId);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson($placeUrl, $payload)->assertCreated()->assertJsonPath('data.id', $orderId);
        self::assertSame(1, Order::query()->where('user_id', $this->customer->id)->count());
        self::assertFalse(Cart::query()->whereKey($cart->id)->exists());
        self::assertSame($orderId, (int) DB::table('commerce_checkout_quotes')
            ->where('id', $quote['quoteId'])->value('consumed_order_id'));
    }

    public function test_stale_quote_after_price_change_cannot_create_order_or_consume_cart(): void
    {
        [$cart, $product] = $this->preparedCart();
        $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', [
            'section' => 'restaurant', 'cartId' => $cart->id,
            'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
        ])->assertCreated()->json('data');
        $product->update(['price' => 18000]);

        $this->withHeader('Idempotency-Key', 'rv2-quote-'.Str::uuid())
            ->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
                'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
                'quoteId' => $quote['quoteId'],
            ])->assertConflict();

        self::assertSame(0, Order::query()->where('user_id', $this->customer->id)->count());
        self::assertTrue(Cart::query()->whereKey($cart->id)->exists());
        self::assertNull(DB::table('commerce_checkout_quotes')
            ->where('id', $quote['quoteId'])->value('consumed_order_id'));
    }
}

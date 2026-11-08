<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

final class RestaurantV2ReorderContractTest extends TestCase
{
    private User $buyer;

    private Order $order;

    private Product $product;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        $db = DB::selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port');
        self::assertSame('alnadha_worker_test', $db->db);
        self::assertSame(mb_strtolower((string) getenv('COMPUTERNAME')), mb_strtolower($db->host));
        self::assertSame(3306, (int) $db->port);
        DB::beginTransaction();

        $this->buyer = User::factory()->create(['email' => 'reorder-'.Str::uuid().'@example.test']);
        $merchant = Restaurant::factory()->create(['user_id' => $this->buyer->id, 'is_active' => true]);
        // Keep product categories scoped to this seeded test merchant. Using
        // the ProductFactory's default category creates an unrelated merchant
        // and user with a Faker email that can collide with existing QA data.
        $this->category = Category::factory()->for($merchant, 'restaurant')->create();
        $this->product = Product::factory()->for($merchant, 'restaurant')->create([
            'category_id' => $this->category->id,
            'price' => 30,
            'is_available' => true,
        ]);
        $this->order = Order::factory()->create([
            'user_id' => $this->buyer->id, 'restaurant_id' => $merchant->id,
            'status' => 'completed', 'order_type' => 'pickup',
        ]);
        OrderItem::query()->create([
            'order_id' => $this->order->id, 'product_id' => $this->product->id,
            'product_name' => $this->product->name, 'quantity' => 2,
            'unit_price' => 25, 'total_price' => 50,
        ]);
        Sanctum::actingAs($this->buyer);
        config()->set('restaurant_v2.enabled', true);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_preview_does_not_write_and_apply_is_revision_bound(): void
    {
        $url = '/api/v2/user/restaurants/orders/'.$this->order->id;
        $preview = $this->postJson($url.'/reorder-preview')
            ->assertOk()->assertJsonPath('data.canApply', true)->assertJsonPath('data.lines.0.status', 'price_changed')
            ->json('data');
        self::assertSame(0, Cart::query()->where('user_id', $this->buyer->id)->count());

        $this->postJson($url.'/reorder', ['previewToken' => $preview['previewToken']])
            ->assertOk()->assertJsonPath('data.itemsAdded', 1)->assertJsonPath('data.cart.productsCount', 2);
        $this->postJson($url.'/reorder', ['previewToken' => $preview['previewToken']])->assertConflict();
        self::assertSame(2, (int) Cart::query()->where('user_id', $this->buyer->id)->firstOrFail()->items()->sum('quantity'));
    }

    public function test_removed_item_requires_review_and_cannot_apply(): void
    {
        $this->product->update(['is_available' => false, 'unavailable_until' => null]);
        $url = '/api/v2/user/restaurants/orders/'.$this->order->id.'/reorder-preview';
        $this->postJson($url)->assertOk()
            ->assertJsonPath('data.canApply', false)
            ->assertJsonPath('data.previewToken', null)
            ->assertJsonPath('data.lines.0.status', 'unavailable');
        self::assertSame(0, Cart::query()->where('user_id', $this->buyer->id)->count());
    }

    public function test_other_owner_cannot_preview_and_tampered_token_is_rejected(): void
    {
        $url = '/api/v2/user/restaurants/orders/'.$this->order->id;
        $this->postJson($url.'/reorder', ['previewToken' => 'tampered'])->assertUnprocessable();

        $other = User::factory()->create(['email' => 'reorder-other-'.Str::uuid().'@example.test']);
        Sanctum::actingAs($other);
        $this->postJson($url.'/reorder-preview')->assertNotFound();
    }

    public function test_order_projection_retains_legacy_fields_and_exposes_iso_and_reasons(): void
    {
        $this->order->update(['pickup_scheduled_for' => now()->addDay()]);
        $this->getJson('/api/v1/user/orders/restaurant/'.$this->order->id)
            ->assertOk()
            ->assertJsonPath('data.actions.canCancel', false)
            ->assertJsonPath('data.actionReasons.canCancel', 'ORDER_STATUS_LOCKED')
            ->assertJsonStructure(['data' => ['fulfillment' => ['scheduledAt', 'scheduledAtIso']]]);
    }

    public function test_catalog_price_change_after_preview_requires_another_review(): void
    {
        $url = '/api/v2/user/restaurants/orders/'.$this->order->id;
        $token = $this->postJson($url.'/reorder-preview')->assertOk()->json('data.previewToken');
        $this->product->update(['price' => 31]);

        $this->postJson($url.'/reorder', ['previewToken' => $token])->assertConflict();
        self::assertSame(0, Cart::query()->where('user_id', $this->buyer->id)->count());
    }

    public function test_new_cart_after_no_cart_preview_is_revision_conflict(): void
    {
        $url = '/api/v2/user/restaurants/orders/'.$this->order->id;
        $token = $this->postJson($url.'/reorder-preview')->assertOk()->json('data.previewToken');
        $this->app->make(\Modules\User\Services\UserRestaurantCartService::class)->addItem($this->buyer->id, $this->product->id, 1);

        $this->postJson($url.'/reorder', ['previewToken' => $token])->assertConflict();
        self::assertSame(1, (int) Cart::query()->where('user_id', $this->buyer->id)->firstOrFail()->items()->sum('quantity'));
    }

    public function test_last_line_becoming_unavailable_cannot_partially_increment(): void
    {
        $second = Product::factory()->for($this->product->restaurant, 'restaurant')->create([
            'category_id' => $this->category->id, 'price' => 12, 'is_available' => true,
        ]);
        OrderItem::query()->create(['order_id' => $this->order->id, 'product_id' => $second->id,
            'product_name' => $second->name, 'quantity' => 1, 'unit_price' => 12, 'total_price' => 12]);
        $url = '/api/v2/user/restaurants/orders/'.$this->order->id;
        $token = $this->postJson($url.'/reorder-preview')->assertOk()->json('data.previewToken');
        $second->update(['is_available' => false]);

        $this->postJson($url.'/reorder', ['previewToken' => $token])->assertConflict();
        self::assertSame(0, Cart::query()->where('user_id', $this->buyer->id)->count());
    }

    public function test_unavailable_line_must_be_deliberately_excluded_before_atomic_apply(): void
    {
        $unavailable = Product::factory()->for($this->product->restaurant, 'restaurant')
            ->create(['category_id' => $this->category->id, 'price' => 12, 'is_available' => false]);
        OrderItem::query()->create([
            'order_id' => $this->order->id,
            'product_id' => $unavailable->id,
            'product_name' => $unavailable->name,
            'quantity' => 1,
            'unit_price' => 12,
            'total_price' => 12,
        ]);
        $base = '/api/v2/user/restaurants/orders/'.$this->order->id;
        $initial = $this->postJson($base.'/reorder-preview')
            ->assertOk()->assertJsonPath('data.canApply', false)->assertJsonPath('data.previewToken', null)
            ->json('data');
        self::assertCount(2, $initial['lines']);
        self::assertSame(0, Cart::query()->where('user_id', $this->buyer->id)->count());

        $availableLine = collect($initial['lines'])->firstWhere('productId', $this->product->id);
        self::assertNotNull($availableLine);
        $reviewed = $this->postJson($base.'/reorder-preview', [
            'lines' => [[
                'orderItemId' => $availableLine['orderItemId'],
                'quantity' => 3,
                'modifierIds' => [],
                'note' => 'Reviewed quantity only',
            ]],
        ])->assertOk()->assertJsonPath('data.canApply', true)->json('data');
        self::assertCount(1, $reviewed['lines']);
        self::assertSame(3, $reviewed['lines'][0]['quantity']);

        $this->postJson($base.'/reorder', ['previewToken' => $reviewed['previewToken']])
            ->assertOk()->assertJsonPath('data.itemsAdded', 1)->assertJsonPath('data.cart.productsCount', 3);
        $cart = Cart::query()->where('user_id', $this->buyer->id)->firstOrFail();
        self::assertSame(3, (int) $cart->items()->sum('quantity'));
        self::assertFalse($cart->items()->where('product_id', $unavailable->id)->exists());
    }
}

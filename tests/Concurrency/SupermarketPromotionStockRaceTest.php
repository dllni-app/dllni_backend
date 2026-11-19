<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/** Runs separately against a disposable MySQL database; no enclosing test transaction. */
final class SupermarketPromotionStockRaceTest extends TestCase
{
    public function test_two_checkouts_cannot_both_reserve_the_last_reward_group(): void
    {
        if (! function_exists('pcntl_fork')) {
            if (getenv('CI')) {
                $this->fail('The MySQL concurrency gate requires the pcntl extension.');
            }
            $this->markTestSkipped('This process race runs on Linux CI with pcntl.');
        }
        self::assertSame('testing', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        self::assertSame('dllni_promotions_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['supermarket-promotions.enabled' => true]);
        Queue::fake();
        Notification::fake();
        $this->withHeader('X-Supermarket-Promotions', '1');
        $store = SmStore::factory()->create(['is_active' => true, 'is_temporarily_closed' => false, 'suspension_until' => null]);
        $product = SmProduct::factory()->create(['store_id' => $store->id, 'price' => 100, 'discounted_price' => null, 'stock_quantity' => 3, 'is_available' => true, 'expires_at' => null]);
        $offer = SmOffer::create(['store_id' => $store->id, 'name' => 'Last group', 'offer_type' => 'value', 'discount_value' => 0,
            'promotion_type' => 'buy_x_get_y', 'buy_quantity' => 2, 'reward_quantity' => 1, 'reward_type' => 'same_product',
            'reward_discount_percent' => 100, 'is_repeatable' => true, 'is_active' => true]);
        $offer->offerProducts()->create(['product_id' => $product->id]);
        $attempts = [];
        foreach ([1, 2] as $index) {
            $customer = User::factory()->create();
            $cart = SmCart::create(['user_id' => $customer->id, 'store_id' => $store->id]);
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100, 'modifier_ids' => []]);
            Sanctum::actingAs($customer);
            $quote = $this->postJson('/api/v1/user/commerce/checkout/quotes', ['section' => 'supermarket', 'cartId' => $cart->id,
                'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate'])->assertCreated()->json('data.quoteId');
            $attempts[] = ['customer' => $customer, 'cart' => $cart->id, 'quote' => $quote, 'result' => tempnam(sys_get_temp_dir(), 'sm-race-result-')];
        }
        $gate = tempnam(sys_get_temp_dir(), 'sm-race-gate-');
        unlink($gate);
        // Never share an open PDO socket between forked processes.
        DB::disconnect();
        $children = [];
        try {
            foreach ($attempts as $index => $attempt) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::purge();
                    Sanctum::actingAs($attempt['customer']);
                    $deadline = microtime(true) + 10;
                    while (! is_file($gate) && microtime(true) < $deadline) {
                        usleep(1000);
                        clearstatcache(true, $gate);
                    }
                    try {
                        if (! is_file($gate)) {
                            throw new RuntimeException('Race gate timed out');
                        }
                        $order = app(UserSupermarketCheckoutPipelineService::class)->place(
                            userId: $attempt['customer']->id, cartId: $attempt['cart'], fulfillmentType: 'pickup', receiveMode: 'immediate',
                            scheduledAt: null, couponCode: null, note: null, idempotencyKey: 'promotion-race-'.$index, quoteId: $attempt['quote'],
                        );
                        $result = ['status' => 201, 'id' => $order->id];
                    } catch (\Illuminate\Validation\ValidationException $exception) {
                        $result = ['status' => 422];
                    } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
                        $result = ['status' => 409];
                    } catch (Throwable $exception) {
                        $result = ['status' => 500, 'error' => $exception::class.': '.$exception->getMessage()];
                    }
                    file_put_contents($attempt['result'], json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $children[] = $pid;
            }
            touch($gate);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            $results = array_map(fn ($attempt) => json_decode(file_get_contents($attempt['result']), true, flags: JSON_THROW_ON_ERROR), $attempts);
            self::assertCount(1, array_filter($results, fn ($result) => $result['status'] === 201), json_encode($results));
            self::assertCount(1, array_filter($results, fn ($result) => in_array($result['status'], [409, 422], true)), json_encode($results));
            self::assertSame(0, (int) $product->fresh()->stock_quantity);
            self::assertSame(1, DB::table('sm_orders')->count());
            self::assertSame(1, DB::table('sm_order_promotion_applications')->count());
            self::assertSame(3, (int) DB::table('sm_order_items')->sum('quantity'));
            self::assertEquals(200, DB::table('sm_orders')->value('subtotal'));
        } finally {
            foreach ([$gate, ...array_column($attempts, 'result')] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}

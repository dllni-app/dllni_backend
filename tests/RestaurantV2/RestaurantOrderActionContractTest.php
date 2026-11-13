<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\User\Services\UserRestaurantCheckoutPipelineService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RestaurantOrderActionContractTest extends TestCase
{
    private User $buyer;

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
        Notification::fake();
        Queue::fake();
        $this->buyer = User::factory()->create(['name' => 'Restaurant V2 transaction fixture', 'email' => 'v2-action-'.Str::uuid().'@example.test']);
        Sanctum::actingAs($this->buyer);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public static function protectedStates(): array
    {
        return [
            'ready source' => ['ready_for_pickup', null],
            'completed source' => ['completed', null],
            'courier picked up' => ['preparing', 'picked_up'],
            'courier returning' => ['preparing', 'returning_to_merchant'],
            'courier completed' => ['preparing', 'completed'],
        ];
    }

    public function test_pickup_cancellation_is_owned_and_idempotent_without_leaking_internal_marker(): void
    {
        $order = $this->fixtureOrder('pending', 'pickup');
        $url = '/api/v1/user/orders/restaurant/'.$order->id.'/cancel';
        $this->postJson($url, ['reason' => 'Local transaction QA'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonMissingPath('data._cancelledNow');
        $this->postJson($url, ['reason' => 'Repeat local transaction QA'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonMissingPath('data._cancelledNow');
        self::assertSame(1, OrderStatusLog::query()->where('order_id', $order->id)->count());
        self::assertSame('20.00', $order->fresh()->total_amount);

        Sanctum::actingAs(User::factory()->create(['email' => 'v2-other-'.Str::uuid().'@example.test']));
        $this->postJson($url)->assertNotFound();
    }

    public function test_linked_cancellation_closes_assignment_releases_driver_and_syncs_source_once(): void
    {
        $order = $this->fixtureOrder('preparing', 'delivery');
        // Keep this local QA fixture isolated from deterministic seeded driver emails.
        $driver = DeliveryDriver::factory()->busy()->create([
            'user_id' => User::factory()->create([
                'module_type' => UserModuleType::DeliveryDriver->value,
                'email' => 'v2-driver-'.Str::uuid().'@example.test',
            ])->id,
        ]);
        $delivery = DeliveryOrder::factory()->create([
            'company_id' => $driver->company_id,
            'driver_id' => $driver->id,
            'created_by_user_id' => $this->buyer->id,
            'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER,
            'source_id' => $order->id,
            'status' => 'accepted',
            'merchant_status' => 'preparing',
            'delivery_fee' => 0,
        ]);
        $attempt = DeliveryAssignmentAttempt::query()->create([
            'order_id' => $delivery->id, 'driver_id' => $driver->id,
            'attempt_no' => 1, 'status' => 'open', 'offered_at' => now(),
            'expires_at' => now()->addMinute(),
        ]);
        $url = '/api/v1/user/orders/restaurant/'.$order->id.'/cancel';
        $this->postJson($url, ['reason' => 'Local transaction QA'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        self::assertSame('cancelled', $delivery->fresh()->status);
        self::assertSame('cancelled', $delivery->fresh()->merchant_status);
        self::assertSame('cancelled', $attempt->fresh()->status);
        self::assertSame('available', $driver->fresh()->availability_status);
        self::assertSame(1, OrderStatusLog::query()->where('order_id', $order->id)->count());
        $events = $delivery->events()->count();
        $this->postJson($url)->assertOk();
        self::assertSame($events, $delivery->events()->count());
        self::assertSame(1, OrderStatusLog::query()->where('order_id', $order->id)->count());
    }

    #[DataProvider('protectedStates')]
    public function test_stale_cancellation_cannot_change_protected_source_or_delivery(string $sourceStatus, ?string $deliveryStatus): void
    {
        $order = $this->fixtureOrder($sourceStatus, $deliveryStatus === null ? 'pickup' : 'delivery');
        $delivery = $deliveryStatus === null ? null : DeliveryOrder::factory()->create([
            'source_type' => DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER,
            'source_id' => $order->id,
            'created_by_user_id' => $this->buyer->id,
            'merchant_status' => $sourceStatus,
            'status' => $deliveryStatus,
            'delivery_fee' => 0,
        ]);
        $this->postJson('/api/v1/user/orders/restaurant/'.$order->id.'/cancel')->assertConflict();
        self::assertSame($sourceStatus, $order->fresh()->status->value);
        self::assertSame(0, OrderStatusLog::query()->where('order_id', $order->id)->count());
        if ($delivery !== null) {
            self::assertSame($deliveryStatus, $delivery->fresh()->status);
            self::assertSame($sourceStatus, $delivery->fresh()->merchant_status);
        }
    }

    public function test_only_eligible_pickup_orders_can_be_rescheduled_and_delivery_quotes_reject_scheduling(): void
    {
        $future = now()->addDay()->toIso8601String();
        $pickup = $this->fixtureOrder('pending', 'pickup');
        $this->patchJson('/api/v1/user/orders/restaurant/'.$pickup->id.'/schedule', ['scheduledAt' => $future])
            ->assertOk()->assertJsonPath('data.fulfillment.receiveMode', 'scheduled_pickup');
        foreach ([['completed', 'pickup'], ['pending', 'delivery']] as [$status, $type]) {
            $order = $this->fixtureOrder($status, $type);
            $this->patchJson('/api/v1/user/orders/restaurant/'.$order->id.'/schedule', ['scheduledAt' => $future])->assertConflict();
            self::assertNull($order->fresh()->pickup_scheduled_for);
        }
        try {
            app(UserRestaurantCheckoutPipelineService::class)->preview(
                (int) $this->buyer->id, 0, 'delivery', 'scheduled', $future, null, null,
            );
            self::fail('Scheduled delivery must be rejected before a quote is issued.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('receiveMode', $error->errors());
        }
    }

    private function fixtureOrder(string $status, string $type): Order
    {
        // Reuse real persisted purchase amounts; only action-state fixtures are synthetic.
        $source = Order::query()->where('order_number', 'ORD-7PKO9PPG-5195')->firstOrFail();
        $order = $source->replicate();
        $order->forceFill([
            'user_id' => $this->buyer->id,
            'order_number' => 'V2-TRANSACTION-'.Str::upper(Str::random(10)),
            'status' => $status, 'order_type' => $type,
            'pickup_mode' => 'immediate_pickup', 'pickup_scheduled_for' => null,
            'cancelled_at' => null, 'cancellation_reason' => null,
        ]);
        Order::withoutEvents(fn () => $order->save());

        return $order;
    }
}

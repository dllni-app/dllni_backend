<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;

it('returns owned delivery orders with tracking details and rejects foreign orders', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->create([
        'company_id' => $company->id,
    ]);

    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5140,
        'longitude' => 36.2767,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);
    $driver = $driver->fresh(['latestLocation']);

    $ownedOrder = DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
        'created_by_user_id' => $owner->id,
        'status' => DeliveryOrderStatus::Accepted->value,
        'accepted_at' => now()->subMinutes(12),
        'started_at' => now()->subMinutes(8),
        'picked_up_at' => null,
        'delivered_at' => null,
        'completed_at' => null,
    ]);

    $foreignOrder = DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
        'created_by_user_id' => $otherUser->id,
    ]);

    Sanctum::actingAs($owner);

    $listResponse = $this->getJson('/api/v1/delivery/user/orders?perPage=10');
    $listResponse
        ->assertOk()
        ->assertJsonPath('data.0.id', $ownedOrder->id);

    expect(collect($listResponse->json('data'))->pluck('id')->all())->not->toContain($foreignOrder->id);

    $detailResponse = $this->getJson('/api/v1/delivery/user/orders/'.$ownedOrder->id);
    $detailResponse
        ->assertOk()
        ->assertJsonPath('data.id', $ownedOrder->id)
        ->assertJsonPath('data.driver.id', $driver->id)
        ->assertJsonPath('data.driver.latestLocation.recordedAt', Carbon::parse($driver->latestLocation->recorded_at)->toIso8601String())
        ->assertJsonPath('data.tracking.currentStatus', DeliveryOrderStatus::Accepted->value)
        ->assertJsonPath('data.tracking.map.enabled', true)
        ->assertJsonStructure([
            'data' => [
                'tracking' => [
                    'eta',
                    'map',
                    'timeline',
                    'driver',
                    'pickup',
                    'dropoff',
                    'route',
                ],
            ],
        ]);

    $this->getJson('/api/v1/delivery/user/orders/'.$foreignOrder->id)->assertNotFound();
});

it('exposes delivery summary in restaurant and supermarket order resources', function (): void {
    $restaurantOrder = Order::factory()->create([
        'order_type' => OrderType::Delivery->value,
        'status' => OrderStatus::ReadyForPickup->value,
        'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
        'ready_for_pickup_at' => now()->subMinutes(15),
        'picked_up_at' => null,
        'completed_at' => null,
        'cancelled_at' => null,
    ]);

    $restaurantPayload = OrderResource::make($restaurantOrder)->resolve();

    expect($restaurantPayload['deliverySummary'])->not()->toBeNull()
        ->and($restaurantPayload['deliverySummary']['enabled'])->toBeTrue()
        ->and($restaurantPayload['deliverySummary']['timeline'])->toBeArray();

    $smOrder = SmOrder::factory()->create([
        'status' => SmOrderStatus::ReadyForPickup->value,
        'pickup_mode' => SmPickupMode::ImmediatePickup->value,
        'ready_for_pickup_at' => now()->subMinutes(10),
        'picked_up_at' => null,
        'cancelled_at' => null,
    ]);

    $smPayload = SmOrderResource::make($smOrder)->resolve();

    expect($smPayload['deliverySummary'])->not()->toBeNull()
        ->and($smPayload['deliverySummary']['enabled'])->toBeTrue()
        ->and($smPayload['deliverySummary']['timeline'])->toBeArray();
});

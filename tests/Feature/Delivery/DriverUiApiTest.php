<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverDispatchService;

function makeDriverWithLocation(DeliveryCompany $company): DeliveryDriver
{
    $driver = DeliveryDriver::factory()->available()->create([
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

    return $driver->fresh();
}

function driverUiPayload(): array
{
    return [
        'customerName' => 'Ahmad Customer',
        'customerPhone' => '+963900000001',
        'pickupAddress' => 'Pickup Street',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff Street',
        'dropoffLatitude' => 33.5200,
        'dropoffLongitude' => 36.2900,
    ];
}

it('returns bootstrap payload and unread notifications count', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = makeDriverWithLocation($company);
    Sanctum::actingAs($driver->user);

    $response = $this->getJson('/api/v1/delivery/driver/bootstrap');
    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'driver',
                'availability',
                'unread_notifications',
                'wallet' => ['current_balance', 'financial_limit', 'threshold_ratio', 'warning_level'],
                'config' => ['reject_reasons', 'min_supported_version', 'latest_version'],
            ],
        ]);
});

it('supports ui order endpoints and notification actions', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = makeDriverWithLocation($company);
    Sanctum::actingAs($driver->user);

    $order = app(DeliveryOrderService::class)->create($company, driverUiPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    $offer = $this->getJson('/api/v1/delivery/driver/offers/current')->json('data');
    expect($offer['id'])->not->toBeNull();

    $this->getJson('/api/v1/delivery/driver/orders?status=WAITING_ACCEPTANCE')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    $this->getJson('/api/v1/delivery/driver/orders?filter[status]=WAITING_ACCEPTANCE')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    $this->getJson('/api/v1/delivery/driver/dashboard/summary')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'activeOrdersCount',
                'completedTodayCount',
                'rejectedOffersTodayCount',
                'missedOffersTodayCount',
                'currentBalance',
                'currency',
                'availabilityStatus',
            ],
        ]);

    $this->getJson('/api/v1/delivery/driver/orders/status-counts')
        ->assertOk()
        ->assertJsonStructure(['data' => ['WAITING_ACCEPTANCE', 'ACTIVE', 'COMPLETED', 'REJECTED']]);

    $this->getJson('/api/v1/delivery/driver/orders/'.$order->id.'/offer-state')
        ->assertOk()
        ->assertJsonStructure(['data' => ['order_id', 'attempt_id', 'is_open', 'offer_expires_at', 'server_time']]);

    $this->postJson('/api/v1/delivery/driver/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    $this->getJson('/api/v1/delivery/driver/notifications/unread-count')
        ->assertOk()
        ->assertJsonStructure(['data' => ['count']]);

    $this->getJson('/api/v1/delivery/driver/config/reject-reasons')
        ->assertOk()
        ->assertJsonStructure(['data' => [['code', 'label', 'requires_text']]]);

    $this->getJson('/api/v1/delivery/driver/app/version-check?version=1.0.0')
        ->assertOk()
        ->assertJsonStructure(['data' => ['current', 'min_supported', 'latest', 'must_update']]);
});

it('handles arrived pickup and dropoff transitions with idempotency', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = makeDriverWithLocation($company);
    Sanctum::actingAs($driver->user);

    $order = app(DeliveryOrderService::class)->create($company, driverUiPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attemptId = (int) $order->fresh()->assignmentAttempts()->latest('id')->value('id');

    $this->postJson('/api/v1/delivery/driver/offers/'.$attemptId.'/accept')->assertOk();

    $arrivedPickup = $this->postJson('/api/v1/delivery/driver/orders/'.$order->id.'/arrived-pickup');
    $arrivedPickup->assertOk()->assertJsonPath('data.status', DeliveryOrderStatus::InProgress->value);

    // Repeated tap should stay successful.
    $this->postJson('/api/v1/delivery/driver/orders/'.$order->id.'/arrived-pickup')->assertOk();

    $this->postJson('/api/v1/delivery/driver/orders/'.$order->id.'/pickup')->assertOk();

    $arrivedDropoff = $this->postJson('/api/v1/delivery/driver/orders/'.$order->id.'/arrived-dropoff');
    $arrivedDropoff->assertOk()->assertJsonPath('data.status', DeliveryOrderStatus::PickedUp->value);

    $this->postJson('/api/v1/delivery/driver/orders/'.$order->id.'/call-events', [
        'type' => 'CUSTOMER_CALL_ATTEMPTED',
    ])->assertOk()->assertJsonPath('data.ok', true);

    $this->getJson('/api/v1/delivery/driver/orders/'.$order->id.'/timeline')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

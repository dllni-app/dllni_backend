<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverDispatchService;

function createDeliveryDriverWithLocation(
    DeliveryCompany $company,
    float $latitude = 33.5140,
    float $longitude = 36.2767,
): DeliveryDriver {
    $driver = DeliveryDriver::factory()->available()->create([
        'company_id' => $company->id,
    ]);

    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);

    return $driver->fresh();
}

function deliveryOrderPayload(): array
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

it('creates an order with pricing and queues dispatch', function (): void {
    Queue::fake();

    $company = DeliveryCompany::factory()->create();
    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());

    expect($order->status)->toBe(DeliveryOrderStatus::Dispatching->value);
    expect((float) $order->distance_km)->toBeGreaterThan(0);
    expect((float) $order->delivery_fee)->toBeGreaterThan(0);

    Queue::assertPushed(DispatchDeliveryOrderJob::class, fn (DispatchDeliveryOrderJob $job): bool => true);
    $this->assertDatabaseHas('delivery_order_events', [
        'order_id' => $order->id,
        'to_status' => DeliveryOrderStatus::Dispatching->value,
    ]);
    $this->assertDatabaseHas('booking_status_logs', [
        'booking_id' => $order->id,
        'booking_type' => 'delivery_order',
        'to_status' => DeliveryOrderStatus::Dispatching->value,
    ]);
});

it('dispatches an offer to the nearest eligible driver', function (): void {
    $company = DeliveryCompany::factory()->create();
    createDeliveryDriverWithLocation($company);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    $order->refresh();

    expect($order->status)->toBe(DeliveryOrderStatus::Offered->value);
    expect($order->assignmentAttempts)->toHaveCount(1);
    expect($order->assignmentAttempts->first()->status)->toBe(DeliveryAssignmentAttemptStatus::Open->value);
});

it('accepts an offer and assigns the driver', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = createDeliveryDriverWithLocation($company);
    Sanctum::actingAs($driver->user);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->firstOrFail();

    $response = $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept");

    $response->assertOk();
    $response->assertJsonPath('data.status', DeliveryOrderStatus::Accepted->value);
    $this->assertDatabaseHas('delivery_drivers', [
        'id' => $driver->id,
        'availability_status' => 'busy',
    ]);
});

it('rejects an offer and redispatches to another driver', function (): void {
    Queue::fake();

    $company = DeliveryCompany::factory()->create();
    $firstDriver = createDeliveryDriverWithLocation($company, 33.5140, 36.2767);
    $secondDriver = createDeliveryDriverWithLocation($company, 33.5145, 36.2770);
    Sanctum::actingAs($firstDriver->user);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = DeliveryAssignmentAttempt::query()
        ->where('order_id', $order->id)
        ->where('driver_id', $firstDriver->id)
        ->firstOrFail();

    $response = $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/reject", [
        'reason' => 'Too far away',
    ]);

    $response->assertOk();
    Queue::assertPushed(DispatchDeliveryOrderJob::class);

    $this->assertDatabaseHas('delivery_assignment_attempts', [
        'id' => $attempt->id,
        'status' => DeliveryAssignmentAttemptStatus::Rejected->value,
    ]);

    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect(DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->count())->toBe(2);
    expect(DeliveryAssignmentAttempt::query()
        ->where('order_id', $order->id)
        ->where('driver_id', $secondDriver->id)
        ->exists())->toBeTrue();
});

it('expires an open attempt and moves to the next driver', function (): void {
    $company = DeliveryCompany::factory()->create();
    $firstDriver = createDeliveryDriverWithLocation($company, 33.5140, 36.2767);
    createDeliveryDriverWithLocation($company, 33.5145, 36.2770);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = DeliveryAssignmentAttempt::query()
        ->where('order_id', $order->id)
        ->where('driver_id', $firstDriver->id)
        ->firstOrFail();

    $this->travel(config('delivery.dispatch.offer_timeout_seconds') + 1)->seconds();

    app(DriverDispatchService::class)->expireAttempt($attempt->id);
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect($attempt->fresh()->status)->toBe(DeliveryAssignmentAttemptStatus::TimedOut->value);
    expect(DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->count())->toBe(2);
});

it('runs the full driver order lifecycle', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = createDeliveryDriverWithLocation($company);
    Sanctum::actingAs($driver->user);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->firstOrFail();

    $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept")->assertOk();

    $order->refresh();

    $this->postJson("/api/v1/delivery/driver/orders/{$order->id}/start")->assertOk();
    $this->postJson("/api/v1/delivery/driver/orders/{$order->id}/pickup")->assertOk();
    $deliverResponse = $this->postJson("/api/v1/delivery/driver/orders/{$order->id}/deliver");

    $deliverResponse->assertOk();
    $deliverResponse->assertJsonPath('data.status', DeliveryOrderStatus::Completed->value);

    $this->assertDatabaseHas('delivery_drivers', [
        'id' => $driver->id,
        'availability_status' => 'available',
    ]);
    $this->assertDatabaseHas('booking_status_logs', [
        'booking_id' => $order->id,
        'booking_type' => 'delivery_order',
        'to_status' => DeliveryOrderStatus::Completed->value,
    ]);
    $this->assertDatabaseHas('delivery_financial_transactions', [
        'reference_type' => DeliveryOrder::class,
        'reference_id' => $order->id,
        'transaction_type' => 'order_fee_debit',
    ]);
});

it('returns 409 when accepting an expired offer', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = createDeliveryDriverWithLocation($company);
    Sanctum::actingAs($driver->user);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->firstOrFail();

    $this->travel(config('delivery.dispatch.offer_timeout_seconds') + 1)->seconds();
    $attempt->forceFill(['status' => DeliveryAssignmentAttemptStatus::TimedOut->value])->save();

    $response = $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept");

    $response->assertStatus(409);
});

it('returns 409 when another driver tries to accept an offer', function (): void {
    $company = DeliveryCompany::factory()->create();
    $assignedDriver = createDeliveryDriverWithLocation($company);
    $otherDriver = createDeliveryDriverWithLocation($company);
    Sanctum::actingAs($otherDriver->user);

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = DeliveryAssignmentAttempt::query()
        ->where('driver_id', $assignedDriver->id)
        ->firstOrFail();

    $response = $this->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept");

    $response->assertStatus(409);
});

it('keeps dispatching and retries when no eligible drivers exist', function (): void {
    Queue::fake();

    $company = DeliveryCompany::factory()->create();

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    $order->refresh();

    expect($order->status)->toBe(DeliveryOrderStatus::Dispatching->value);
    expect($order->stop_reason)->toBeNull();
    Queue::assertPushed(DispatchDeliveryOrderJob::class);
});

it('stops dispatch after the no-candidate retry budget is exhausted', function (): void {
    Queue::fake();
    config()->set('delivery.dispatch.max_no_candidate_retries', 1);

    $company = DeliveryCompany::factory()->create();

    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect($order->fresh()->status)->toBe(DeliveryOrderStatus::Dispatching->value);

    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect($order->fresh()->status)->toBe(DeliveryOrderStatus::Stopped->value);
    expect($order->fresh()->stop_reason)->not->toBeNull();
});

it('retries dispatch after a stopped order', function (): void {
    Queue::fake();

    $company = DeliveryCompany::factory()->create();
    $order = app(DeliveryOrderService::class)->create($company, deliveryOrderPayload());
    app(DeliveryOrderService::class)->markStopped($order, 'Manual dispatch stop.');

    expect($order->fresh()->status)->toBe(DeliveryOrderStatus::Stopped->value);

    createDeliveryDriverWithLocation($company);

    $retried = app(DeliveryOrderService::class)->retryDispatch($order->fresh());

    expect($retried->status)->toBe(DeliveryOrderStatus::Dispatching->value);
    Queue::assertPushed(DispatchDeliveryOrderJob::class);
});

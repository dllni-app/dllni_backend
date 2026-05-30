<?php

declare(strict_types=1);

use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverDispatchService;

it('ranks nearer drivers ahead of farther drivers', function (): void {
    $company = DeliveryCompany::factory()->create();

    $nearDriver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $nearDriver->id,
        'latitude' => 33.5140,
        'longitude' => 36.2767,
        'recorded_at' => now(),
    ]);

    $farDriver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $farDriver->id,
        'latitude' => 33.5300,
        'longitude' => 36.3000,
        'recorded_at' => now(),
    ]);

    $order = app(DeliveryOrderService::class)->create($company, [
        'customerName' => 'Customer',
        'pickupAddress' => 'Pickup',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff',
        'dropoffLatitude' => 33.5200,
        'dropoffLongitude' => 36.2900,
    ]);

    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    $attempt = DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->firstOrFail();

    expect((int) $attempt->driver_id)->toBe((int) $nearDriver->id);
    expect((float) $attempt->distance_to_pickup_km)->toBeLessThan(1);
});

it('is idempotent when dispatch is called for a non-dispatching order', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5140,
        'longitude' => 36.2767,
        'recorded_at' => now(),
    ]);

    $order = app(DeliveryOrderService::class)->create($company, [
        'customerName' => 'Customer',
        'pickupAddress' => 'Pickup',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff',
        'dropoffLatitude' => 33.5200,
        'dropoffLongitude' => 36.2900,
    ]);

    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect(DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->count())->toBe(1);
    expect($order->fresh()->status)->toBe(DeliveryOrderStatus::Offered->value);
});

it('prevents a driver with an active order from accepting another offer', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);

    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5140,
        'longitude' => 36.2767,
        'recorded_at' => now(),
    ]);

    $activeOrder = app(DeliveryOrderService::class)->create($company, [
        'customerName' => 'Active Customer',
        'pickupAddress' => 'Pickup A',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff A',
        'dropoffLatitude' => 33.5200,
        'dropoffLongitude' => 36.2900,
    ]);

    $activeOrder->forceFill([
        'driver_id' => $driver->id,
        'status' => DeliveryOrderStatus::InProgress->value,
        'started_at' => now(),
    ])->save();

    $pendingOrder = app(DeliveryOrderService::class)->create($company, [
        'customerName' => 'Pending Customer',
        'pickupAddress' => 'Pickup B',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff B',
        'dropoffLatitude' => 33.5210,
        'dropoffLongitude' => 36.2910,
    ]);

    $attempt = DeliveryAssignmentAttempt::query()->create([
        'order_id' => $pendingOrder->id,
        'driver_id' => $driver->id,
        'attempt_no' => 1,
        'status' => 'open',
        'distance_to_pickup_km' => 0.5,
        'offered_at' => now(),
        'expires_at' => now()->addSeconds(30),
    ]);

    expect(fn () => app(DriverDispatchService::class)->acceptAttempt($attempt->id, $driver))
        ->toThrow(RuntimeException::class, 'Driver already has an active order');
});

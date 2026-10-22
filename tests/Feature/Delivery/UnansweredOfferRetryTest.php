<?php

declare(strict_types=1);

use Database\Factories\DeliveryOrderFactory;
use Illuminate\Support\Facades\Queue;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Services\DriverDispatchService;

/**
 * @return array{order: \Modules\Delivery\Models\DeliveryOrder, driver: DeliveryDriver, attempt: DeliveryAssignmentAttempt}
 */
function qaUnansweredOfferFixture(): array
{
    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 36.211764,
        'longitude' => 37.111561,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);
    $order = DeliveryOrderFactory::new()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::SearchingForDriver->value,
        'pickup_latitude' => 36.2021,
        'pickup_longitude' => 37.1343,
    ]);

    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    return [
        'order' => $order,
        'driver' => $driver,
        'attempt' => DeliveryAssignmentAttempt::query()
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->firstOrFail(),
    ];
}

it('gives a single nearby driver one bounded second chance after an unanswered offer', function (): void {
    Queue::fake();
    config()->set('delivery.dispatch.max_timed_out_reoffers_per_driver', 1);

    ['order' => $order, 'driver' => $driver, 'attempt' => $initial] = qaUnansweredOfferFixture();
    expect($initial->distance_to_pickup_km)->toBeLessThan(5);

    $this->travel(config('delivery.dispatch.offer_timeout_seconds') + 1)->seconds();
    app(DriverDispatchService::class)->expireAttempt($initial->id);
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    $retry = DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

    expect($initial->fresh()->status)->toBe(DeliveryAssignmentAttemptStatus::TimedOut->value)
        ->and($retry->id)->not->toBe($initial->id)
        ->and($retry->driver_id)->toBe($driver->id)
        ->and($retry->attempt_no)->toBe(2)
        ->and($order->fresh()->status)->toBe(DeliveryOrderStatus::Offered->value);
});

it('stops after the configured unanswered retry budget instead of looping forever', function (): void {
    Queue::fake();
    config()->set('delivery.dispatch.max_timed_out_reoffers_per_driver', 1);

    ['order' => $order, 'attempt' => $initial] = qaUnansweredOfferFixture();

    $this->travel(config('delivery.dispatch.offer_timeout_seconds') + 1)->seconds();
    app(DriverDispatchService::class)->expireAttempt($initial->id);
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    $retry = DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    $this->travel(config('delivery.dispatch.offer_timeout_seconds') + 1)->seconds();
    app(DriverDispatchService::class)->expireAttempt($retry->id);
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect($retry->fresh()->status)->toBe(DeliveryAssignmentAttemptStatus::TimedOut->value)
        ->and($order->fresh()->status)->toBe(DeliveryOrderStatus::Stopped->value)
        ->and(DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->count())->toBe(2);
});

it('does not reoffer when the nearby driver explicitly rejected the order', function (): void {
    Queue::fake();
    config()->set('delivery.dispatch.max_timed_out_reoffers_per_driver', 3);

    ['order' => $order, 'driver' => $driver, 'attempt' => $initial] = qaUnansweredOfferFixture();

    app(DriverDispatchService::class)->rejectAttempt($initial->id, $driver, 'Declined');
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);

    expect($initial->fresh()->status)->toBe(DeliveryAssignmentAttemptStatus::Rejected->value)
        ->and($order->fresh()->status)->toBe(DeliveryOrderStatus::Stopped->value)
        ->and(DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->count())->toBe(1);
});

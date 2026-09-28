<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use App\Services\DeliverySystemAlertGenerator;
use App\Services\RestaurantSystemAlertGenerator;
use App\Services\SupermarketSystemAlertGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

uses(RefreshDatabase::class);

it('uses stalled progress taxonomy for restaurant operational alerts', function (): void {
    $order=Order::factory()->create([
        'status'=>'accepted',
        'accepted_at'=>null,
        'estimated_preparation_minutes'=>null,
    ]);
    $order->timestamps=false;
    $order->forceFill(['updated_at'=>now()->subMinutes(30)])->saveQuietly();

    app(RestaurantSystemAlertGenerator::class)->handle();

    expect(SystemAlert::query()
        ->where('booking_id',$order->id)
        ->where('alert_type',AlertType::StalledProgress->value)
        ->exists())->toBeTrue()
        ->and(SystemAlert::query()
            ->where('booking_id',$order->id)
            ->where('alert_type',AlertType::FrozenGPS->value)
            ->exists())->toBeFalse();
});

it('creates deduplicated supermarket operational alerts', function (): void {
    $stalled=SmOrder::factory()->accepted()->create();
    $stalled->timestamps=false;
    $stalled->forceFill(['updated_at'=>now()->subMinutes(30)])->saveQuietly();

    $ready=SmOrder::factory()->readyForPickup()->create([
        'ready_for_pickup_at'=>now()->subMinutes(30),
        'picked_up_at'=>null,
    ]);

    $generator=app(SupermarketSystemAlertGenerator::class);
    expect($generator->handle())->toBe(2)
        ->and($generator->handle())->toBe(0);

    expect(SystemAlert::query()
        ->where('booking_type','supermarket_order')
        ->where('booking_id',$stalled->id)
        ->where('alert_type',AlertType::StalledProgress->value)
        ->where('status',SystemAlertStatus::New->value)
        ->exists())->toBeTrue()
        ->and(SystemAlert::query()
            ->where('booking_type','supermarket_order')
            ->where('booking_id',$ready->id)
            ->where('alert_type',AlertType::ReadyPickupOverdue->value)
            ->exists())->toBeTrue();
});

it('creates delivery alerts for dispatch exhaustion and stale active drivers', function (): void {
    $company=DeliveryCompany::factory()->create();

    $stopped=DeliveryOrder::factory()->create([
        'company_id'=>$company->id,
        'status'=>'stopped',
        'stopped_at'=>now()->subMinutes(5),
        'stop_reason'=>'No eligible drivers',
    ]);

    $driver=DeliveryDriver::factory()->create([
        'company_id'=>$company->id,
        'last_seen_at'=>now()->subMinutes(30),
        'availability_status'=>'busy',
    ]);

    $active=DeliveryOrder::factory()->create([
        'company_id'=>$company->id,
        'driver_id'=>$driver->id,
        'status'=>'accepted',
    ]);

    $generator=app(DeliverySystemAlertGenerator::class);
    expect($generator->handle())->toBe(2)
        ->and($generator->handle())->toBe(0);

    expect(SystemAlert::query()
        ->where('booking_type','delivery_order')
        ->where('booking_id',$stopped->id)
        ->where('alert_type',AlertType::DeliveryDispatchExhausted->value)
        ->exists())->toBeTrue()
        ->and(SystemAlert::query()
            ->where('booking_type','delivery_order')
            ->where('booking_id',$active->id)
            ->where('alert_type',AlertType::StaleDriverLocation->value)
            ->exists())->toBeTrue();
});

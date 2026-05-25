<?php

declare(strict_types=1);

use Modules\User\Services\UserCleaningOrderEstimationService;
use App\Models\CleaningFinancialSetting;
use App\Models\Worker;

it('computes deterministic cleaning size, duration, and tier', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $estimation = $service->estimate('house', [
        'rooms' => 3,
        'bedrooms' => 2,
        'bathrooms' => 1,
        'living_room_size' => 'medium',
    ]);

    expect($estimation['estimatedSqm'])->toBe(171.0);
    expect($estimation['estimatedHours'])->toBe(5.5);
    expect($estimation['sizeTier'])->toBe('large');
});

it('computes distance-based pricing with percent admin margin', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ]
    );

    $worker = Worker::factory()->create([
        'home_address' => 'Worker Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.3,
    ]);

    $service = app(UserCleaningOrderEstimationService::class);

    $pricing = $service->price(
        'apartment',
        [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        33.5,
        36.3,
        $worker->id,
    );

    expect($pricing['basePrice'])->toBe(920.0);
    expect($pricing['distanceKm'])->toBe(11.119);
    expect($pricing['travelFee'])->toBe(111.19);
    expect($pricing['addonsTotal'])->toBe(0.0);
    expect($pricing['adminMargin'])->toBe(103.12);
    expect($pricing['isPricingFinal'])->toBeTrue();
    expect($pricing['totalPrice'])->toBe(1134.31);
    expect($pricing['currency'])->toBe((string) config('app.currency', 'SYP'));
});

it('computes distance-based pricing with fixed admin margin', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 0,
            'commission_type' => 'fixed',
            'commission_fixed_amount' => 75,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ]
    );

    $worker = Worker::factory()->create([
        'home_address' => 'Worker Home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $service = app(UserCleaningOrderEstimationService::class);

    $pricing = $service->price(
        'apartment',
        [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        33.5,
        36.3,
        $worker->id,
    );

    expect($pricing['basePrice'])->toBe(920.0);
    expect($pricing['distanceKm'])->toBe(0.0);
    expect($pricing['travelFee'])->toBe(0.0);
    expect($pricing['adminMargin'])->toBe(75.0);
    expect($pricing['isPricingFinal'])->toBeTrue();
    expect($pricing['totalPrice'])->toBe(995.0);
});

it('returns provisional pricing when preferred worker is not selected', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $pricing = $service->price(
        'apartment',
        [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        33.5,
        36.3,
        null,
    );

    expect($pricing['basePrice'])->toBe(920.0);
    expect($pricing['distanceKm'])->toBeNull();
    expect($pricing['travelFee'])->toBe(0.0);
    expect($pricing['adminMargin'])->toBe(0.0);
    expect($pricing['isPricingFinal'])->toBeFalse();
    expect($pricing['totalPrice'])->toBe(920.0);
});

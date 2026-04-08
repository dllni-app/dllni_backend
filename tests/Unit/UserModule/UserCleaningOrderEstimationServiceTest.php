<?php

declare(strict_types=1);

use Modules\User\Services\UserCleaningOrderEstimationService;

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

it('computes deterministic cleaning pricing breakdown', function (): void {
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
        77,
    );

    expect($pricing['basePrice'])->toBe(920.0);
    expect($pricing['travelFee'])->toBe(150.0);
    expect($pricing['addonsTotal'])->toBe(100.0);
    expect($pricing['totalPrice'])->toBe(1170.0);
    expect($pricing['currency'])->toBe((string) config('app.currency', 'SYP'));
});

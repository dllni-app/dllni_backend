<?php

declare(strict_types=1);

use Modules\User\Services\UserCleaningOrderEstimationService;
use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\ServicePricing;

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

it('computes event assistance estimate and service-based pricing', function (): void {
    $serviceA = CleaningService::query()->create([
        'name' => 'Event service A',
        'slug' => 'event-service-a-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'A',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Event service B',
        'slug' => 'event-service-b-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'B',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 300,
        'price_per_sqm' => null,
        'min_hours' => 3,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'villa',
        'living_room_size' => null,
        'base_price' => 250,
        'price_per_sqm' => null,
        'min_hours' => 2,
    ]);

    $service = app(UserCleaningOrderEstimationService::class);

    $estimation = $service->estimate('event_assistance', [
        'eventType' => 'birthday',
        'guestCount' => 45,
        'venueType' => 'apartment',
    ], [$serviceA->id, $serviceB->id]);

    $pricing = $service->price(
        'event_assistance',
        [
            'eventType' => 'birthday',
            'guestCount' => 45,
            'venueType' => 'apartment',
        ],
        null,
        null,
        null,
        [$serviceA->id, $serviceB->id],
    );

    expect($estimation['recommendation']['suggestedTeamSize'])->toBe(6);
    expect($estimation['estimatedHours'])->toBe(5.0);
    expect($pricing['basePrice'])->toBe(550.0);
    expect($pricing['totalPrice'])->toBe(550.0);
    expect($pricing['serviceLines'])->toHaveCount(2);
});

it('includes selected regular cleaning services in addons pricing', function (): void {
    $serviceA = CleaningService::query()->create([
        'name' => 'Deep cleaning add-on',
        'slug' => 'deep-cleaning-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'A',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Kitchen intensive add-on',
        'slug' => 'kitchen-intensive-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'B',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 100,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 90,
        'price_per_sqm' => null,
        'min_hours' => 1,
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
        null,
        null,
        null,
        [$serviceA->id, $serviceB->id],
    );

    expect($pricing['basePrice'])->toBe(920.0);
    expect($pricing['addonsTotal'])->toBe(190.0);
    expect($pricing['totalPrice'])->toBe(1110.0);
    expect($pricing['serviceLines'])->toHaveCount(2);
});

it('normalizes balcony from room size breakdown', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('apartment', [
        'room_size_breakdown' => [
            'bedroom' => ['small' => 1, 'medium' => 2, 'large' => 1],
            'bathroom' => ['small' => 1, 'medium' => 1, 'large' => 1],
            'kitchen' => ['small' => 1, 'medium' => 0, 'large' => 0],
            'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
            'balcony' => ['small' => 2, 'medium' => 1, 'large' => 0],
        ],
    ]);

    expect($details['balconies'])->toBe(3);
    expect($details['room_size_breakdown']['balcony']['small'])->toBe(2);
    expect($details['room_size_breakdown']['balcony']['medium'])->toBe(1);
});

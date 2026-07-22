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
            'travel_distance_start_point' => 'worker_'.'home',
        ]
    );

    $worker = Worker::factory()->create([
        'home_'.'address' => 'Worker Home',
        'home_'.'latitude' => 33.6,
        'home_'.'longitude' => 36.3,
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

    expect($pricing['basePrice'])->toBe(1000.0);
    expect($pricing['distanceKm'])->toBe(11.119);
    expect($pricing['travelFee'])->toBe(500.0);
    expect($pricing['addonsTotal'])->toBe(0.0);
    expect($pricing['adminMargin'])->toBe(500.0);
    expect($pricing['isPricingFinal'])->toBeTrue();
    expect($pricing['totalPrice'])->toBe(1500.0);
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
            'travel_distance_start_point' => 'worker_'.'home',
        ]
    );

    $worker = Worker::factory()->create([
        'home_'.'address' => 'Worker Home',
        'home_'.'latitude' => 33.5,
        'home_'.'longitude' => 36.3,
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

    expect($pricing['basePrice'])->toBe(1000.0);
    expect($pricing['distanceKm'])->toBe(0.0);
    expect($pricing['travelFee'])->toBe(0.0);
    expect($pricing['adminMargin'])->toBe(500.0);
    expect($pricing['isPricingFinal'])->toBeTrue();
    expect($pricing['totalPrice'])->toBe(1000.0);
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

    expect($pricing['basePrice'])->toBe(1000.0);
    expect($pricing['distanceKm'])->toBeNull();
    expect($pricing['travelFee'])->toBe(0.0);
    expect($pricing['adminMargin'])->toBe(0.0);
    expect($pricing['isPricingFinal'])->toBeFalse();
    expect($pricing['totalPrice'])->toBe(1000.0);
});

it('computes event assistance estimate and hourly pricing', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        ['extension_rate_per_30_minutes' => 150]
    );

    $service = app(UserCleaningOrderEstimationService::class);

    $estimation = $service->estimate('event_assistance', [
        'eventType' => 'birthday',
        'guestCount' => 45,
        'venueType' => 'apartment',
        'customService' => 'Manual event support',
        'hours' => 4,
    ]);

    $pricing = $service->price(
        'event_assistance',
        [
            'eventType' => 'birthday',
            'guestCount' => 45,
            'venueType' => 'apartment',
            'customService' => 'Manual event support',
            'hours' => 4,
        ],
        null,
        null,
    );

    expect($estimation['recommendation']['suggestedTeamSize'])->toBe(5);
    expect($estimation['estimatedHours'])->toBe(4.0);
    expect($pricing['basePrice'])->toBe(2000.0);
    expect($pricing['totalPrice'])->toBe(2000.0);
    expect($pricing['eventHourlyRate'])->toBe(500.0);
    expect($pricing['eventHours'])->toBe(4.0);
    expect($pricing['serviceLines'])->toHaveCount(0);
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

    expect($pricing['basePrice'])->toBe(1000.0);
    expect($pricing['addonsTotal'])->toBe(1000.0);
    expect($pricing['totalPrice'])->toBe(2000.0);
    expect($pricing['serviceLines'])->toHaveCount(2);
});

it('defaults cleaning mode to regular when omitted', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('apartment', [
        'rooms' => 2,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'living_room_size' => 'small',
    ]);

    expect($details['cleaning_mode'])->toBe('regular');
});

it('multiplies deep cleaning estimates and base price by five while keeping add-ons unchanged', function (): void {
    $serviceA = CleaningService::query()->create([
        'name' => 'Deep cleaning add-on',
        'slug' => 'deep-mode-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'A',
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

    $service = app(UserCleaningOrderEstimationService::class);

    $regularEstimation = $service->estimate('apartment', [
        'rooms' => 2,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'living_room_size' => 'small',
    ]);

    $deepEstimation = $service->estimate('apartment', [
        'rooms' => 2,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'living_room_size' => 'small',
        'cleaning_mode' => 'deep',
    ]);

    $regularPricing = $service->price('apartment', [
        'rooms' => 2,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'living_room_size' => 'small',
    ], null, null, null, [$serviceA->id]);

    $deepPricing = $service->price('apartment', [
        'rooms' => 2,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'living_room_size' => 'small',
        'cleaning_mode' => 'deep',
    ], null, null, null, [$serviceA->id]);

    expect($deepEstimation['estimatedSqm'])->toBe($regularEstimation['estimatedSqm']);
    expect($deepEstimation['sizeTier'])->toBe($regularEstimation['sizeTier']);
    expect($deepEstimation['estimatedHours'])->toBe($regularEstimation['estimatedHours'] * 5);
    expect($deepPricing['basePrice'])->toBe($regularPricing['basePrice'] * 5);
    expect($deepPricing['addonsTotal'])->toBe($regularPricing['addonsTotal']);
    expect($deepPricing['totalPrice'])->toBe($deepPricing['basePrice'] + $deepPricing['addonsTotal']);
    expect($deepPricing['serviceLines'])->toHaveCount(1);
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

it('normalizes shed from room size breakdown', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('villa', [
        'room_size_breakdown' => [
            'bedroom' => ['medium' => 1],
            'shed' => ['small' => 1, 'medium' => 1, 'large' => 0],
        ],
    ]);

    expect($details['sheds'])->toBe(2);
    expect($details['room_size_breakdown']['shed']['small'])->toBe(1);
    expect($details['room_size_breakdown']['shed']['medium'])->toBe(1);
    expect($details['room_size_breakdown']['shed']['large'])->toBe(0);
});

it('normalizes legacy sheds count into room size breakdown', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('apartment', [
        'rooms' => 1,
        'bathrooms' => 1,
        'kitchens' => 1,
        'sheds' => 2,
        'living_room_size' => 'medium',
    ]);

    expect($details['sheds'])->toBe(2);
});

it('normalizes partial room size breakdown by filling missing room types and buckets with zero', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('villa', [
        'room_size_breakdown' => [
            'bedroom' => ['large' => 1],
            'bathroom' => ['medium' => 1],
        ],
    ]);

    expect($details['rooms'])->toBe(1);
    expect($details['bathrooms'])->toBe(1);
    expect($details['kitchens'])->toBe(0);
    expect($details['room_size_breakdown']['bedroom']['small'])->toBe(0);
    expect($details['room_size_breakdown']['bedroom']['large'])->toBe(1);
    expect($details['room_size_breakdown']['living_room']['medium'])->toBe(0);
});

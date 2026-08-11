<?php

declare(strict_types=1);

use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\ServicePricing;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('does not include managed cleaning service prices in the order total', function (): void {
    $managedService = CleaningService::query()->create([
        'name' => 'Window Cleaning',
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Legacy description that must not affect pricing.',
        'price' => 999999,
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $managedService->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 888888,
        'price_per_sqm' => 777777,
        'min_hours' => 10,
    ]);

    $service = app(UserCleaningOrderEstimationService::class);
    $propertyDetails = [
        'rooms' => 2,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'living_room_size' => 'small',
    ];

    $withoutManagedServices = $service->price('apartment', $propertyDetails, null, null);
    $withManagedServices = $service->price(
        'apartment',
        $propertyDetails,
        null,
        null,
        null,
        [$managedService->id],
    );

    expect($withManagedServices['basePrice'])->toBe($withoutManagedServices['basePrice'])
        ->and($withManagedServices['addonsTotal'])->toBe(0.0)
        ->and($withManagedServices['totalPrice'])->toBe($withoutManagedServices['totalPrice'])
        ->and($withManagedServices['serviceLines'])->toBe([]);
});

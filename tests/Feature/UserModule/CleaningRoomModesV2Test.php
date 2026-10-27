<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('prices a mix of حوسة and تعزيل room by room and divides elapsed time by the team size', function (): void {
    $units = CleaningFinancialDefaults::roomPricingUnits();
    $units['bedroom']['small'] = 1.0;
    $units['bathroom']['small'] = 1.0;

    $multipliers = CleaningFinancialDefaults::roomDeepMultipliers();
    $multipliers['bedroom']['small'] = 3.0;
    $multipliers['bathroom']['small'] = 2.0;

    $minutes = CleaningFinancialDefaults::roomTimeMinutes();
    $minutes['bedroom']['small']['regular'] = 30;
    $minutes['bedroom']['small']['deep'] = 90;
    $minutes['bathroom']['small']['regular'] = 30;
    $minutes['bathroom']['small']['deep'] = 90;

    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'commission_type' => 'percent',
        'cleaning_base_unit_price' => 50,
        'cleaning_minimum_order_price' => 0,
        'cleaning_deep_multiplier' => 4,
        'cleaning_room_pricing_units' => $units,
        'cleaning_room_deep_multipliers' => $multipliers,
        'cleaning_room_time_minutes' => $minutes,
    ]);

    // Room hourly prices are now controlled by the catalog. Keep this test's
    // simple financial examples independent of whichever seeded catalog rates
    // happen to be installed in the local QA database.
    foreach ([
        'bedroom' => ['regular' => 100, 'deep' => 300],
        'bathroom' => ['regular' => 100, 'deep' => 200],
    ] as $code => $hourly) {
        CleaningRoomType::query()->where('code', $code)->update([
            'regular_hourly_rate' => $hourly['regular'],
            'deep_hourly_rate' => $hourly['deep'],
            'suggested_minutes_by_size' => json_encode($minutes[$code], JSON_THROW_ON_ERROR),
        ]);
    }

    $details = [
        'cleaning_mode' => 'regular',
        'room_size_breakdown' => [
            'bedroom' => ['small' => 1],
            'bathroom' => ['small' => 1],
        ],
    ];

    $service = app(UserCleaningOrderEstimationService::class);
    $regular = $service->price('apartment', $details, null, null);
    $mixedDetails = [
        ...$details,
        'room_cleaning_modes' => [
            'bedroom.small.1' => 'regular',
            'bathroom.small.1' => 'deep',
        ],
    ];
    $mixedOne = $service->price('apartment', $mixedDetails, null, null, workerCount: 1);
    $mixedTwo = $service->price('apartment', $mixedDetails, null, null, workerCount: 2);
    $mixedThree = $service->price('apartment', $mixedDetails, null, null, workerCount: 3);
    $allDeep = $service->price('apartment', [
        ...$details,
        'cleaning_mode' => 'deep',
    ], null, null);

    expect($regular['basePrice'])->toBe(100.0)
        ->and($mixedOne['basePrice'])->toBe(350.0)
        ->and($allDeep['basePrice'])->toBe(750.0)
        ->and($mixedOne['estimatedWorkMinutes'])->toBe(120)
        ->and($mixedOne['estimatedDurationMinutes'])->toBe(120)
        ->and($mixedTwo['estimatedDurationMinutes'])->toBe(60)
        ->and($mixedThree['estimatedDurationMinutes'])->toBe(40)
        ->and($mixedOne['basePrice'])->toBe($mixedTwo['basePrice'])
        ->and($mixedTwo['basePrice'])->toBe($mixedThree['basePrice'])
        ->and(collect($mixedOne['roomPricingLines'])->pluck('cleaningMode')->sort()->values()->all())
        ->toBe(['deep', 'regular'])
        ->and($service->normalizePropertyDetailsForStorage('apartment', $mixedDetails)['room_cleaning_modes'])
        ->toBe($mixedDetails['room_cleaning_modes']);
});

it('retains existing global deep mode when no per-room choices are provided', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);
    $details = ['cleaning_mode' => 'deep', 'room_size_breakdown' => ['bedroom' => ['medium' => 1]]];
    $original = $service->price('apartment', $details, null, null);
    $explicit = $service->price('apartment', [
        ...$details,
        'room_cleaning_modes' => ['bedroom.medium.1' => 'deep'],
    ], null, null);
    expect($original['basePrice'])->toBe($explicit['basePrice'])
        ->and($original['estimatedWorkMinutes'])->toBe($explicit['estimatedWorkMinutes']);
});


it('accepts mixed room cleaning modes through the same HTTP estimate and store payload as Flutter', function (): void {
    $customer = \App\Models\User::factory()->create();
    \Laravel\Sanctum\Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->for($customer)->create([
        'label' => 'QA home',
        'city' => 'Damascus',
        'street' => 'Test Street',
        'building' => '12',
        'floor' => '3',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);
    \App\Models\CancellationPolicy::query()->create([
        'module' => 'cleaning', 'name' => 'QA policy', 'description' => 'QA',
        'rules' => ['free_until_hours' => 24], 'is_active' => true, 'is_default' => true,
    ]);
    \Modules\Cleaning\Models\CleaningBillingPolicy::query()->create([
        'name' => 'QA booking', 'billing_mode' => \Modules\Cleaning\Enums\CleaningBillingMode::FullBookedTime->value,
        'rules' => [], 'is_active' => true, 'is_default' => true,
    ]);

    $payload = [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus, Test Street, Building 12',
            'location_name' => 'QA home',
            'bedrooms' => 2, 'bathrooms' => 0, 'rooms' => 3, 'balconies' => 0,
            'living_room_size' => 'small',
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 0, 'medium' => 2, 'large' => 0],
                'kitchen' => ['small' => 0, 'medium' => 1, 'large' => 0],
            ],
            'room_cleaning_modes' => [
                'bedroom.medium.1' => 'deep',
                'bedroom.medium.2' => 'regular',
                'kitchen.medium.1' => 'regular',
            ],
        ],
        'addressId' => $address->id,
        'scheduledDate' => now()->addDays(2)->toDateString(),
        'scheduledTime' => '09:00',
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 3,
        'genderPreference' => 'any',
        'termsAccepted' => true,
    ];

    $estimate = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)->assertOk();
    expect($estimate->json('size.estimatedDurationMinutes'))->toBeGreaterThan(0);
    $order = $this->postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertCreated();
    $bookingId = (int) $order->json('order.id');
    $persisted = \Modules\Cleaning\Models\CleaningBooking::query()->findOrFail($bookingId);
    expect($persisted->property_details['room_cleaning_modes'])
        ->toBe($payload['propertyDetails']['room_cleaning_modes'])
        ->and((int) $persisted->number_of_workers)->toBe(3);
});

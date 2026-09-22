<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('keeps the booking at the configured minimum until room pricing exceeds it', function (): void {
    $pricingUnits = CleaningFinancialDefaults::roomPricingUnits();
    $pricingUnits['bathroom']['small'] = 1.0;

    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'commission_type' => 'percent',
        'cleaning_base_unit_price' => 50,
        'cleaning_minimum_order_price' => 150,
        'cleaning_deep_multiplier' => 4,
        'cleaning_room_pricing_units' => $pricingUnits,
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    $service = app(UserCleaningOrderEstimationService::class);

    $kitchenOnly = $service->price('apartment', [
        'room_size_breakdown' => [
            'kitchen' => ['small' => 1],
        ],
    ], null, null);

    $kitchenAndLivingRoom = $service->price('apartment', [
        'room_size_breakdown' => [
            'kitchen' => ['small' => 1],
            'living_room' => ['medium' => 1],
        ],
    ], null, null);

    $aboveMinimum = $service->price('apartment', [
        'room_size_breakdown' => [
            'kitchen' => ['small' => 1],
            'living_room' => ['medium' => 1],
            'bathroom' => ['small' => 1],
        ],
    ], null, null);

    expect($kitchenOnly['pricingAlgorithm']['calculatedBasePrice'])->toBe(50.0)
        ->and($kitchenOnly['basePrice'])->toBe(150.0)
        ->and($kitchenOnly['pricingAlgorithm']['minimumOrderApplied'])->toBeTrue()
        ->and($kitchenAndLivingRoom['pricingAlgorithm']['calculatedBasePrice'])->toBe(125.0)
        ->and($kitchenAndLivingRoom['basePrice'])->toBe(150.0)
        ->and($kitchenAndLivingRoom['pricingAlgorithm']['minimumOrderApplied'])->toBeTrue()
        ->and($aboveMinimum['pricingAlgorithm']['calculatedBasePrice'])->toBe(175.0)
        ->and($aboveMinimum['basePrice'])->toBe(175.0)
        ->and($aboveMinimum['pricingAlgorithm']['minimumOrderApplied'])->toBeFalse();
});

it('uses the deep-cleaning multiplier configured for each room type and size', function (): void {
    $multipliers = CleaningFinancialDefaults::roomDeepMultipliers();
    $multipliers['bedroom']['small'] = 2.0;
    $multipliers['kitchen']['small'] = 3.0;

    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'commission_type' => 'percent',
        'cleaning_base_unit_price' => 50,
        'cleaning_minimum_order_price' => 0,
        'cleaning_deep_multiplier' => 4,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => $multipliers,
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    $pricing = app(UserCleaningOrderEstimationService::class)->price('apartment', [
        'room_size_breakdown' => [
            'bedroom' => ['small' => 1],
            'kitchen' => ['small' => 1],
        ],
        'cleaning_mode' => 'deep',
    ], null, null);

    $lines = collect($pricing['roomPricingLines'])->keyBy('roomType');

    expect($pricing['basePrice'])->toBe(250.0)
        ->and((float) $lines['bedroom']['modeMultiplier'])->toBe(2.0)
        ->and((float) $lines['bedroom']['totalPrice'])->toBe(100.0)
        ->and((float) $lines['kitchen']['modeMultiplier'])->toBe(3.0)
        ->and((float) $lines['kitchen']['totalPrice'])->toBe(150.0);
});


it('treats the configured minimum as the final customer service price including commission', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 25,
        'commission_type' => 'percent',
        'commission_fixed_amount' => null,
        'cleaning_base_unit_price' => 500,
        'cleaning_minimum_order_price' => 1500,
        'cleaning_deep_multiplier' => 4,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    $pricing = app(UserCleaningOrderEstimationService::class)->price('apartment', [
        'room_size_breakdown' => [
            'kitchen' => ['small' => 1],
        ],
    ], null, null);

    expect($pricing['pricingAlgorithm']['minimumOrderApplied'])->toBeTrue()
        ->and($pricing['basePrice'])->toBe(1500.0)
        ->and($pricing['adminMargin'])->toBe(375.0)
        ->and($pricing['totalPrice'])->toBe(1500.0);
});

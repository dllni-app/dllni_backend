<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
use Modules\User\Services\UserCleaningOrderEstimationService;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 25,
        'commission_type' => 'percent',
        'commission_fixed_amount' => null,
        'cleaning_base_unit_price' => 245,
        'cleaning_minimum_order_price' => 1500,
        'cleaning_deep_multiplier' => 3,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(3),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 300,
    ]);
});

it('only applies the customer minimum after commission, not to the raw large room base', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);
    $oneLarge = $service->price('apartment', [
        'room_size_breakdown' => ['bedroom' => ['large' => 1]],
        'cleaning_mode' => 'deep',
    ], null, null);
    $twoLarge = $service->price('apartment', [
        'room_size_breakdown' => ['bedroom' => ['large' => 2]],
        'cleaning_mode' => 'deep',
    ], null, null);
    $oneSmall = $service->price('apartment', [
        'room_size_breakdown' => ['bedroom' => ['small' => 1]],
        'cleaning_mode' => 'deep',
    ], null, null);

    // One large bedroom: 245 * 2 * 3 = 1470, plus ceil(25%) = 368.
    // No 1500 minimum: customer service = 1838 and travel = 300.
    expect($oneLarge['pricingAlgorithm']['calculatedBasePrice'])->toBe(1470.0)
        ->and($oneLarge['pricingAlgorithm']['minimumOrderApplied'])->toBeFalse()
        ->and($oneLarge['basePrice'])->toBe(1470.0)
        ->and($oneLarge['adminMargin'])->toBe(368.0)
        ->and($oneLarge['totalPrice'])->toBe(2138.0)
        ->and($twoLarge['pricingAlgorithm']['minimumOrderApplied'])->toBeFalse()
        ->and($twoLarge['basePrice'])->toBe(2940.0)
        ->and($twoLarge['adminMargin'])->toBe(735.0)
        ->and($twoLarge['totalPrice'])->toBe(3975.0)
        ->and($oneSmall['pricingAlgorithm']['minimumOrderApplied'])->toBeTrue()
        ->and($oneSmall['basePrice'])->toBe(1500.0)
        ->and($oneSmall['totalPrice'])->toBe(1800.0);
});

it('retains the minimum decision after saving a new booking', function (): void {
    $large = CleaningBooking::factory()->create([
        'property_type' => 'apartment',
        'base_price' => 1470,
        'minimum_order_applied' => false,
        'addons_total' => 0,
        'number_of_workers' => 1,
        'is_pricing_final' => false,
    ])->fresh();
    $minimum = CleaningBooking::factory()->create([
        'property_type' => 'apartment',
        'base_price' => 1500,
        'minimum_order_applied' => true,
        'addons_total' => 0,
        'number_of_workers' => 1,
        'is_pricing_final' => false,
    ])->fresh();
    $exactBase = CleaningBooking::factory()->create([
        'property_type' => 'apartment',
        'base_price' => 1500,
        'minimum_order_applied' => false,
        'addons_total' => 0,
        'number_of_workers' => 1,
        'is_pricing_final' => false,
    ])->fresh();

    expect((float) $large->admin_margin_amount)->toBe(368.0)
        ->and((float) $large->total_price)->toBe(2138.0)
        ->and((float) $minimum->total_price)->toBe(1800.0)
        ->and((float) $exactBase->total_price)->toBe(2175.0);
});

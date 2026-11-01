<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningPricingCalculator;

it('does not round new SYP service amounts up to 500', function (): void {
    $calculator = app(CleaningPricingCalculator::class);

    expect($calculator->roundMoney(240))->toBe(240.0);
    expect($calculator->roundMoney(320))->toBe(320.0);
    expect($calculator->roundMoney(960))->toBe(960.0);
});

it('deducts commission from the service revenue without adding it to the customer price', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
        ],
    );

    $calculator = app(CleaningPricingCalculator::class);

    $regularCleaning = $calculator->provisional(240);
    expect($regularCleaning['adminMargin'])->toBe(60.0);
    expect($regularCleaning['totalPrice'])->toBe(240.0);

    $eventAssistance = $calculator->provisional(320 * 3);
    expect($eventAssistance['adminMargin'])->toBe(240.0);
    expect($eventAssistance['totalPrice'])->toBe(960.0);
});

it('adds the configured transport allowance for every worker before and after worker pricing is finalized', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 0,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_markup_type' => 'worker_allowance',
            'travel_markup_value' => 75,
        ],
    );

    $calculator = app(CleaningPricingCalculator::class);

    $provisional = $calculator->provisional(1000, 0, 0, 3);
    expect($provisional['travelFee'])->toBe(225.0)
        ->and($provisional['totalPrice'])->toBe(1225.0)
        ->and($provisional['isPricingFinal'])->toBeTrue();

    $worker = Worker::factory()->make(['home_address' => null, 'home_latitude' => null, 'home_longitude' => null]);
    $final = $calculator->finalizedForWorker(
        1000,
        0,
        null,
        null,
        $worker,
    );

    expect($final['travelFee'])->toBe(75.0)
        ->and($final['distanceKm'])->toBeNull()
        ->and($final['totalPrice'])->toBe(1075.0)
        ->and($final['isPricingFinal'])->toBeTrue();

    $booking = CleaningBooking::factory()->create([
        'number_of_workers' => 3,
        'base_price' => 1000,
        'addons_total' => 0,
        'admin_margin_amount' => 0,
        'travel_fee' => 0,
        'total_price' => 1000,
        'is_pricing_final' => false,
    ])->fresh();

    expect((float) $booking->travel_fee)->toBe(225.0)
        ->and((float) $booking->total_price)->toBe(1225.0);
});

it('keeps commission inside the customer total when the minimum price includes it', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
        ],
    );

    $pricing = app(CleaningPricingCalculator::class)->provisional(1500, 0, 1500);

    expect($pricing['adminMargin'])->toBe(375.0)
        ->and($pricing['totalPrice'])->toBe(1500.0);
});

it('keeps commission in the customer subtotal even when the minimum base is exceeded', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
        ],
    );

    $pricing = app(CleaningPricingCalculator::class)->provisional(1500, 200, 1500);

    expect($pricing['adminMargin'])->toBe(425.0)
        ->and($pricing['includedAdminMargin'])->toBe(375.0)
        ->and($pricing['totalPrice'])->toBe(1700.0);
});

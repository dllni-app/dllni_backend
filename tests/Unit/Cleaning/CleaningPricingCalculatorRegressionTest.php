<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningPricingCalculator;

it('does not round new SYP service amounts up to 500', function (): void {
    $calculator = app(CleaningPricingCalculator::class);

    expect($calculator->roundMoney(240))->toBe(240.0);
    expect($calculator->roundMoney(320))->toBe(320.0);
    expect($calculator->roundMoney(960))->toBe(960.0);
});

it('includes the configured percent commission in provisional pricing', function (): void {
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
    expect($regularCleaning['totalPrice'])->toBe(300.0);

    $eventAssistance = $calculator->provisional(320 * 3);
    expect($eventAssistance['adminMargin'])->toBe(240.0);
    expect($eventAssistance['totalPrice'])->toBe(1200.0);
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
            'travel_per_km' => 10,
        ],
    );

    $calculator = app(CleaningPricingCalculator::class);

    $provisional = $calculator->provisional(1000, 0, 0, 3);
    expect($provisional['travelFee'])->toBe(225.0)
        ->and($provisional['totalPrice'])->toBe(1225.0);

    $final = $calculator->finalizedForCoordinates(
        1000,
        0,
        36.2,
        37.1,
        36.2,
        37.1,
    );

    expect($final['travelFee'])->toBe(85.0)
        ->and($final['totalPrice'])->toBe(1085.0)
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

it('adds commission only for amounts above the commission-inclusive minimum base', function (): void {
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
        ->and($pricing['totalPrice'])->toBe(1750.0);
});

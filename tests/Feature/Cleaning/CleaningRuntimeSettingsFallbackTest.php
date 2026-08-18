<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

beforeEach(function (): void {
    DB::table('cleaning_financial_settings')->delete();
    DB::table('cleaning_deposit_settings')->delete();
});

it('uses hard-coded operational defaults without inserting configuration rows', function (): void {
    $financial = CleaningRuntimeSettings::financial();
    $deposit = CleaningRuntimeSettings::deposit();

    expect((float) $financial->default_commission_rate)->toBe(10.0)
        ->and((int) $financial->travel_per_km)->toBe(10)
        ->and((int) $financial->extension_rate_per_30_minutes)->toBe(200)
        ->and($financial->extension_ranges)->toBe(CleaningRuntimeSettings::extensionRanges())
        ->and((int) $financial->cleaning_base_unit_price)->toBe(50)
        ->and((float) $financial->cleaning_deep_multiplier)->toBe(4.0)
        ->and((float) $deposit->minimum_deposit_amount)->toBe(0.0)
        ->and((float) $deposit->allowance_warning_threshold_percent)->toBe(10.0)
        ->and((int) $deposit->trust_reject_after_accept_penalty)->toBe(10)
        ->and((int) $deposit->trust_minimum_for_dispatch)->toBe(0)
        ->and(DB::table('cleaning_financial_settings')->exists())->toBeFalse()
        ->and(DB::table('cleaning_deposit_settings')->exists())->toBeFalse();
});

it('keeps database configuration authoritative when a row exists', function (): void {
    CleaningFinancialSetting::query()->create([
        'default_commission_rate' => 17.5,
        'vat_rate' => 0,
        'commission_type' => 'percent',
        'travel_markup_type' => 'fixed',
        'travel_markup_value' => 0,
        'travel_per_km' => 42,
        'coverage_thresholds' => ['low' => 5, 'ok' => 9],
        'time_billing_mode' => 'actual',
        'extension_rate_per_30_minutes' => 350,
        'extension_ranges' => [
            ['start' => 0, 'end' => 15, 'price' => 99],
        ],
        'cleaning_base_unit_price' => 75,
        'cleaning_deep_multiplier' => 3,
    ]);

    $financial = CleaningRuntimeSettings::financial();

    expect((float) $financial->default_commission_rate)->toBe(17.5)
        ->and((int) $financial->travel_per_km)->toBe(42)
        ->and((int) $financial->extension_rate_per_30_minutes)->toBe(350)
        ->and((int) $financial->cleaning_base_unit_price)->toBe(75)
        ->and((float) $financial->cleaning_deep_multiplier)->toBe(3.0)
        ->and($financial->coverage_thresholds)->toBe(['low' => 5, 'ok' => 9]);
});

it('prices cleaning and extension requests when the financial settings table is empty', function (): void {
    $calculator = app(CleaningPricingCalculator::class);
    $pricing = $calculator->provisional(1000);
    $ranges = app(CleaningExtendedTimePricingService::class)->ranges();

    expect($pricing['adminMargin'])->toBe(100.0)
        ->and($pricing['totalPrice'])->toBe(1100.0)
        ->and($ranges)->toHaveCount(6)
        ->and($ranges[0]['price'])->toBe(10.0)
        ->and($ranges[5]['price'])->toBe(500.0)
        ->and(DB::table('cleaning_financial_settings')->exists())->toBeFalse();
});

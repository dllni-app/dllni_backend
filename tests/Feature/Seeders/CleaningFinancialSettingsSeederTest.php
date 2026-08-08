<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

beforeEach(function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);
});

it('seeds cleaning financial settings with the event hourly rate and new SYP prices', function (): void {
    $setting = CleaningFinancialSetting::query()->find(1);

    expect($setting)->not->toBeNull();
    expect((float) $setting->extension_rate_per_30_minutes)->toBe(200.0);
    expect((float) $setting->travel_per_km)->toBe(10.0);
    expect((float) $setting->cleaning_base_unit_price)->toBe(50.0);
    expect(CleaningFinancialDefaults::BASE_UNIT_PRICE)->toBe(50.0);
    expect((float) $setting->default_commission_rate)->toBe(10.0);
});

it('provides cleaning extended time ranges using the new Syrian currency values', function (): void {
    $ranges = app(CleaningExtendedTimePricingService::class)->ranges();

    expect($ranges)->toHaveCount(6)
        ->and($ranges[0])->toMatchArray([
            'startMinutes' => 0,
            'endMinutes' => 15,
            'price' => 10.0,
            'currency' => 'SYP',
        ])
        ->and($ranges[1])->toMatchArray([
            'startMinutes' => 16,
            'endMinutes' => 30,
            'price' => 25.0,
            'currency' => 'SYP',
        ])
        ->and($ranges[5])->toMatchArray([
            'startMinutes' => 76,
            'endMinutes' => 90,
            'price' => 500.0,
            'currency' => 'SYP',
        ]);
});

it('is idempotent when run twice', function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);

    expect(CleaningFinancialSetting::query()->count())->toBe(1);
    expect((float) CleaningFinancialSetting::query()->value('extension_rate_per_30_minutes'))->toBe(200.0);
});

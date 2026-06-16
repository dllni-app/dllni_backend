<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

beforeEach(function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);
});

it('seeds cleaning financial settings with event assistance extension rate', function (): void {
    $setting = CleaningFinancialSetting::query()->find(1);

    expect($setting)->not->toBeNull();
    expect((float) $setting->extension_rate_per_30_minutes)->toBe(4500.0);
    expect((float) $setting->travel_per_km)->toBe(100.0);
    expect((float) $setting->default_commission_rate)->toBe(10.0);
});

it('provides cleaning extended time ranges from the seeded financial setting', function (): void {
    $ranges = app(CleaningExtendedTimePricingService::class)->ranges();

    expect($ranges)->toHaveCount(6)
        ->and($ranges[1])->toMatchArray([
            'startMinutes' => 16,
            'endMinutes' => 30,
            'price' => 4500.0,
            'currency' => 'SYP',
        ]);
});

it('is idempotent when run twice', function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);

    expect(CleaningFinancialSetting::query()->count())->toBe(1);
    expect((float) CleaningFinancialSetting::query()->value('extension_rate_per_30_minutes'))->toBe(4500.0);
});

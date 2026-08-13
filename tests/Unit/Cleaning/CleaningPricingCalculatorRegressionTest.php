<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
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

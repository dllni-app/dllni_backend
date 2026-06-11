<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 0,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'extension_rate_per_30_minutes' => 4500,
        ],
    );
});

it('returns the configured price for each fixed cleaning extension minute range', function (
    int $minutes,
    int $startMinutes,
    int $endMinutes,
    float $price
): void {
    $quote = app(CleaningExtendedTimePricingService::class)->quote($minutes);

    expect($quote['requestedMinutes'])->toBe($minutes)
        ->and($quote['matchedRange'])->toMatchArray([
            'startMinutes' => $startMinutes,
            'endMinutes' => $endMinutes,
            'label' => "{$startMinutes} - {$endMinutes} minutes",
            'price' => $price,
            'currency' => 'SYP',
        ])
        ->and($quote['calculatedExtensionPrice'])->toBe($price);
})->with([
    '0-15 minutes' => [0, 0, 15, 2250.0],
    '16-30 minutes' => [16, 16, 30, 4500.0],
    '31-45 minutes' => [31, 31, 45, 6750.0],
    '46-60 minutes' => [46, 46, 60, 9000.0],
    '61-75 minutes' => [61, 61, 75, 11250.0],
    '76-90 minutes' => [90, 76, 90, 13500.0],
]);

it('fails validation when cleaning extension minutes exceed 90', function (): void {
    app(CleaningExtendedTimePricingService::class)->quote(91);
})->throws(ValidationException::class);

it('returns all fixed cleaning extension ranges from the configured financial setting', function (): void {
    $ranges = app(CleaningExtendedTimePricingService::class)->ranges();

    expect($ranges)->toHaveCount(6)
        ->and($ranges[3])->toMatchArray([
            'startMinutes' => 46,
            'endMinutes' => 60,
            'label' => '46 - 60 minutes',
            'price' => 9000.0,
            'currency' => 'SYP',
        ]);
});

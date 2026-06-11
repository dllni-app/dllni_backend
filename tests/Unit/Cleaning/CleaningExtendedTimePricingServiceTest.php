<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningExtendedTimePrice;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

it('returns the configured price for each fixed cleaning extension minute range', function (
    int $minutes,
    int $startMinutes,
    int $endMinutes,
    float $price
): void {
    CleaningExtendedTimePrice::query()
        ->where('start_minutes', $startMinutes)
        ->where('end_minutes', $endMinutes)
        ->update(['price' => $price]);

    $quote = app(CleaningExtendedTimePricingService::class)->quote($minutes);

    expect($quote['requestedMinutes'])->toBe($minutes)
        ->and($quote['matchedRange'])->toMatchArray([
            'startMinutes' => $startMinutes,
            'endMinutes' => $endMinutes,
            'label' => "{$startMinutes} - {$endMinutes} minutes",
            'price' => $price,
            'currency' => (string) config('app.currency', 'SYP'),
        ])
        ->and($quote['calculatedExtensionPrice'])->toBe($price);
})->with([
    '0-15 minutes' => [0, 0, 15, 1000.0],
    '16-30 minutes' => [16, 16, 30, 2000.0],
    '31-45 minutes' => [31, 31, 45, 3000.0],
    '46-60 minutes' => [46, 46, 60, 4000.0],
    '61-75 minutes' => [61, 61, 75, 5000.0],
    '76-90 minutes' => [90, 76, 90, 6000.0],
]);

it('fails validation when cleaning extension minutes exceed 90', function (): void {
    app(CleaningExtendedTimePricingService::class)->quote(91);
})->throws(ValidationException::class);

it('returns all fixed cleaning extension ranges with configured database prices', function (): void {
    CleaningExtendedTimePrice::query()
        ->where('start_minutes', 46)
        ->where('end_minutes', 60)
        ->update(['price' => 9876.5]);

    $ranges = app(CleaningExtendedTimePricingService::class)->ranges();

    expect($ranges)->toHaveCount(6)
        ->and($ranges[3])->toMatchArray([
            'startMinutes' => 46,
            'endMinutes' => 60,
            'label' => '46 - 60 minutes',
            'price' => 9876.5,
            'currency' => (string) config('app.currency', 'SYP'),
        ]);
});

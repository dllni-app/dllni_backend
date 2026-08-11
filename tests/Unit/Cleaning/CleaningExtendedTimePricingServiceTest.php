<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 0,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'extension_rate_per_30_minutes' => 200,
            'extension_ranges' => [
                ['start' => 0, 'end' => 15, 'price' => 10],
                ['start' => 16, 'end' => 30, 'price' => 25],
                ['start' => 31, 'end' => 45, 'price' => 50],
                ['start' => 46, 'end' => 60, 'price' => 100],
                ['start' => 61, 'end' => 75, 'price' => 200],
                ['start' => 76, 'end' => 90, 'price' => 500],
            ],
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
            'label' => "من {$startMinutes} إلى {$endMinutes} دقيقة",
            'price' => $price,
            'currency' => 'SYP',
        ])
        ->and($quote['calculatedExtensionPrice'])->toBe($price);
})->with([
    '0-15 minutes' => [0, 0, 15, 10.0],
    '16-30 minutes' => [16, 16, 30, 25.0],
    '31-45 minutes' => [31, 31, 45, 50.0],
    '46-60 minutes' => [46, 46, 60, 100.0],
    '61-75 minutes' => [61, 61, 75, 200.0],
    '76-90 minutes' => [90, 76, 90, 500.0],
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
            'label' => 'من 46 إلى 60 دقيقة',
            'price' => 100.0,
            'currency' => 'SYP',
        ]);
});

it('adds the booking effective administration margin to an extension quote', function (): void {
    $booking = CleaningBooking::factory()->create([
        'base_price' => 10000,
        'addons_total' => 0,
        'admin_margin_amount' => 1000,
    ]);

    $quote = app(CleaningExtendedTimePricingService::class)
        ->quoteForBooking($booking, 30);

    expect($quote)->toMatchArray([
        'baseAmount' => 25.0,
        'adminMargin' => 2.5,
        'totalAmount' => 27.5,
        'calculatedExtensionPrice' => 27.5,
    ])->and($quote['matchedRange'])->toMatchArray([
        'price' => 27.5,
        'baseAmount' => 25.0,
        'adminMargin' => 2.5,
        'totalAmount' => 27.5,
    ]);
});

it('derives an effective rate from a fixed booking margin snapshot', function (): void {
    $booking = CleaningBooking::factory()->create([
        'base_price' => 8000,
        'addons_total' => 0,
        'admin_margin_amount' => 2000,
    ]);

    $quote = app(CleaningExtendedTimePricingService::class)
        ->quoteForBooking($booking, 30);

    expect($quote['baseAmount'])->toBe(25.0)
        ->and($quote['adminMargin'])->toBe(6.25)
        ->and($quote['totalAmount'])->toBe(31.25);
});

it('uses zero extension margin when the booking service subtotal is zero', function (): void {
    $booking = CleaningBooking::factory()->create([
        'base_price' => 0,
        'addons_total' => 0,
        'admin_margin_amount' => 1000,
    ]);

    $quote = app(CleaningExtendedTimePricingService::class)
        ->quoteForBooking($booking, 30);

    expect($quote['adminMargin'])->toBe(0.0)
        ->and($quote['totalAmount'])->toBe(25.0);
});

<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Services\CleaningTimeWarningService;

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

it('adds the booking effective administration margin rounded to whole SYP to an extension quote', function (): void {
    $booking = CleaningBooking::factory()->create([
        'base_price' => 10000,
        'addons_total' => 0,
        'admin_margin_amount' => 1000,
    ]);

    $quote = app(CleaningExtendedTimePricingService::class)
        ->quoteForBooking($booking, 30);

    expect($quote)->toMatchArray([
        'baseAmount' => 25.0,
        'adminMargin' => 3.0,
        'totalAmount' => 28.0,
        'calculatedExtensionPrice' => 28.0,
    ])->and($quote['matchedRange'])->toMatchArray([
        'price' => 28.0,
        'baseAmount' => 25.0,
        'adminMargin' => 3.0,
        'totalAmount' => 28.0,
    ]);
});

it('derives an effective rate from a fixed booking margin snapshot using whole SYP', function (): void {
    $booking = CleaningBooking::factory()->create([
        'base_price' => 8000,
        'addons_total' => 0,
        'admin_margin_amount' => 2000,
    ]);

    $quote = app(CleaningExtendedTimePricingService::class)
        ->quoteForBooking($booking, 30);

    expect($quote['baseAmount'])->toBe(25.0)
        ->and($quote['adminMargin'])->toBe(7.0)
        ->and($quote['totalAmount'])->toBe(32.0);
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

it('quotes and persists fractional configured extension prices in whole SYP without losing a lira', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'extension_ranges' => [
            ['start' => 0, 'end' => 15, 'price' => 10],
            ['start' => 16, 'end' => 30, 'price' => 25.25],
            ['start' => 31, 'end' => 45, 'price' => 50],
            ['start' => 46, 'end' => 60, 'price' => 100],
            ['start' => 61, 'end' => 75, 'price' => 200],
            ['start' => 76, 'end' => 90, 'price' => 500],
        ],
    ]);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'base_price' => 10000,
        'addons_total' => 0,
        'admin_margin_amount' => 1000,
        'total_price' => 11000,
        'extension_fee_total' => 0,
    ]);
    $quote = app(CleaningExtendedTimePricingService::class)->quoteForBooking($booking, 30);

    expect($quote['baseAmount'])->toBe(26.0)
        ->and($quote['adminMargin'])->toBe(3.0)
        ->and($quote['totalAmount'])->toBe(29.0)
        ->and($quote['calculatedExtensionPrice'])->toBe(29.0);

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'sent_at' => now(),
        'additional_minutes' => 30,
        'quoted_base_amount' => $quote['baseAmount'],
        'quoted_admin_margin_amount' => $quote['adminMargin'],
        'quoted_amount' => $quote['calculatedExtensionPrice'],
        'quoted_currency' => 'SYP',
    ]);

    expect($warning->fresh()->quoted_base_amount)->toBe(26)
        ->and($warning->fresh()->quoted_admin_margin_amount)->toBe(3)
        ->and($warning->fresh()->quoted_amount)->toBe(29);

    // An approved quote is charged exactly once at its persisted whole-SYP
    // value, even when the worker retries the same decision.
    app(CleaningTimeWarningService::class)->accept($warning);
    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(29.0)
        ->and((float) $booking->admin_margin_amount)->toBe(1003.0)
        ->and((float) $booking->total_price)->toBe(11029.0);

    app(CleaningTimeWarningService::class)->accept($warning->fresh());
    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(29.0)
        ->and((float) $booking->admin_margin_amount)->toBe(1003.0)
        ->and((float) $booking->total_price)->toBe(11029.0);
});

it('signs a requested extension from the current locked booking instead of a stale caller snapshot', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'base_price' => 10000,
        'addons_total' => 0,
        'admin_margin_amount' => 1000,
        'number_of_workers' => 1,
    ]);

    // Simulate a preloaded Customer request racing with an already persisted
    // pricing revision. The incoming Eloquent instance still contains 10%.
    $staleBooking = $booking->fresh();
    // Simulate a previously committed revision without triggering unrelated
    // booking observer side effects in this stale-request race fixture.
    DB::table('cleaning_bookings')->where('id', $booking->id)
        ->update(['admin_margin_amount' => 2000]);

    expect((float) $staleBooking->admin_margin_amount)->toBe(1000.0)
        ->and((float) $booking->fresh()->admin_margin_amount)->toBe(2000.0)
        ->and(app(CleaningExtendedTimePricingService::class)
            ->quoteForBooking($booking->fresh(), 30)['adminMargin'])->toBe(5.0);

    $result = app(CleaningBookingWorkerCompletionService::class)
        ->requestExtension($staleBooking, 30);

    expect($result['extensionPricing']['baseAmount'])->toBe(25.0)
        ->and($result['extensionPricing']['adminMargin'])->toBe(5.0)
        ->and($result['extensionPricing']['totalAmount'])->toBe(30.0)
        ->and($result['warning']->fresh()->quoted_base_amount)->toBe(25)
        ->and($result['warning']->fresh()->quoted_admin_margin_amount)->toBe(5)
        ->and($result['warning']->fresh()->quoted_amount)->toBe(30)
        ->and($result['booking']->status)->toBe(CleaningBookingStatus::TimeExtensionRequested);
});

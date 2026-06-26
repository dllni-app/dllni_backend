<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Http\Resources\EventBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use Modules\Cleaning\Services\CleaningOrderUrgencyService;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00', config('app.timezone')));

    CleaningFinancialSetting::query()->create([
        'default_commission_rate' => 0,
        'vat_rate' => 0,
        'travel_markup_type' => 'fixed',
        'travel_markup_value' => 0,
        'extension_rate_per_30_minutes' => 9000,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('detects current-date bookings as hot orders', function (): void {
    $service = app(CleaningOrderUrgencyService::class);

    expect($service->isHotOrder(now(config('app.timezone'))->toDateString()))->toBeTrue();
    expect($service->isHotOrder(now(config('app.timezone'))->addDay()->toDateString()))->toBeFalse();
});

it('prepends the hot order prefix only once', function (): void {
    $service = app(CleaningOrderUrgencyService::class);

    $title = 'طلب تنظيف #CLN-TEST';
    $prefixedTitle = CleaningOrderUrgencyService::HOT_ORDER_PREFIX.' '.$title;

    expect($service->displayTitle($title, now(config('app.timezone'))->toDateString()))->toBe($prefixedTitle);
    expect($service->prependHotPrefix($prefixedTitle))->toBe($prefixedTitle);
});

it('adds hot-order fields to current-date cleaning booking resources', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_number' => 'CLN-TEST',
        'property_type' => 'apartment',
        'scheduled_date' => now(config('app.timezone'))->toDateString(),
    ]);

    $payload = CleaningBookingResource::make($booking)->resolve(request());

    expect($payload['isHotOrder'])->toBeTrue();
    expect($payload['is_hot_order'])->toBeTrue();
    expect($payload['urgencyLabel'])->toBe(CleaningOrderUrgencyService::HOT_ORDER_LABEL);
    expect($payload['urgencyPrefix'])->toBe(CleaningOrderUrgencyService::HOT_ORDER_PREFIX);
    expect($payload['displayTitle'])->toBe(CleaningOrderUrgencyService::HOT_ORDER_PREFIX.' طلب تنظيف #CLN-TEST');
});

it('keeps future cleaning booking resources non-hot', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_number' => 'CLN-FUTURE',
        'property_type' => 'apartment',
        'scheduled_date' => now(config('app.timezone'))->addDay()->toDateString(),
    ]);

    $payload = CleaningBookingResource::make($booking)->resolve(request());

    expect($payload['isHotOrder'])->toBeFalse();
    expect($payload['urgencyLabel'])->toBeNull();
    expect($payload['urgencyPrefix'])->toBeNull();
    expect($payload['displayTitle'])->toBe('طلب تنظيف #CLN-FUTURE');
});

it('adds hot-order fields to current-date event booking resources', function (): void {
    $booking = EventBooking::factory()->create([
        'booking_number' => 'EVT-TEST',
        'scheduled_date' => now(config('app.timezone'))->toDateString(),
    ]);

    $payload = EventBookingResource::make($booking)->resolve(request());

    expect($payload['isHotOrder'])->toBeTrue();
    expect($payload['is_hot_order'])->toBeTrue();
    expect($payload['urgencyLabel'])->toBe(CleaningOrderUrgencyService::HOT_ORDER_LABEL);
    expect($payload['urgencyPrefix'])->toBe(CleaningOrderUrgencyService::HOT_ORDER_PREFIX);
    expect($payload['displayTitle'])->toBe(CleaningOrderUrgencyService::HOT_ORDER_PREFIX.' طلب مناسبة #EVT-TEST');
});

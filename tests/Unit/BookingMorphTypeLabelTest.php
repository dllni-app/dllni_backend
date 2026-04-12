<?php

declare(strict_types=1);

use App\Support\BookingMorphTypeLabel;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Resturants\Models\Order;

it('returns dash for empty morph type', function (): void {
    expect(BookingMorphTypeLabel::resolve(null))->toBe('-')
        ->and(BookingMorphTypeLabel::resolve(''))->toBe('-');
});

it('maps restaurant order class to a label without namespaces', function (): void {
    expect(BookingMorphTypeLabel::resolve(Order::class))
        ->not->toContain('\\');
});

it('maps legacy cleaning_booking slug', function (): void {
    app()->setLocale('en');

    expect(BookingMorphTypeLabel::resolve('cleaning_booking'))
        ->toBe(__('cleaning_admin.booking_morph_labels.cleaning'));
});

it('maps cleaning booking class to cleaning label', function (): void {
    app()->setLocale('en');

    expect(BookingMorphTypeLabel::resolve(CleaningBooking::class))
        ->toBe(__('cleaning_admin.booking_morph_labels.cleaning'));
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningBooking;

uses(RefreshDatabase::class);

it('adds optional V3 contract identity without changing legacy bookings or V2 quote mapping', function (): void {
    expect(Schema::hasColumns('cleaning_bookings', ['contract_version', 'catalog_version_id']))->toBeTrue()
        ->and(Schema::hasColumns('cleaning_booking_quotes', ['contract_version', 'catalog_version_id']))->toBeTrue()
        ->and(Schema::hasColumns('cleaning_booking_sessions', [
            'appointment_key', 'occurrence_key', 'price_revision', 'clock_revision',
        ]))->toBeTrue();

    $booking = CleaningBooking::factory()->create(['total_price' => 3300]);

    expect($booking->getRawOriginal('contract_version'))->toBeNull()
        ->and($booking->getRawOriginal('catalog_version_id'))->toBeNull()
        ->and((float) $booking->getRawOriginal('total_price'))->toBe(3300.0);

    // Legacy V2 accepted quotes deliberately retain multiple booking headers
    // (one per appointment sequence). A global quote_id unique key would break V2.
    $quoteIndexes = collect(Schema::getIndexes('cleaning_quote_bookings'))
        ->filter(static fn (array $index): bool => $index['unique'])
        ->map(static fn (array $index): array => $index['columns'])
        ->values()
        ->all();

    expect($quoteIndexes)->toContain(['quote_id', 'appointment_sequence'])
        ->not->toContain(['quote_id']);
});

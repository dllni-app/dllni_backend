<?php

declare(strict_types=1);

use Database\Seeders\CleaningIndependentOrdersSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;

it('protects the operational independent-order scenario seeder from SQLite test mutations', function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite');

    expect(fn () => app(CleaningIndependentOrdersSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Independent order QA fixtures require the existing local MySQL test database.');

    expect(CleaningBooking::query()->where('booking_number', 'CLN-QA-INDEPENDENT-ORDER-1')->exists())->toBeFalse();
});

<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

uses(RefreshDatabase::class);

it('restores the missing visit funding schema without changing V2 booking amounts', function (): void {
    expect(Schema::hasColumns('cleaning_visit_funding_reservations', [
        'reservation_key', 'funding_source_id', 'cleaning_booking_session_id',
        'active_visit_id', 'quote_evidence_digest', 'reserved_amount',
        'currency', 'worker_obligation_amount', 'supervisor_obligation_amount',
        'liability_snapshot', 'reservation_state', 'approved_by', 'approved_at',
        'released_at',
    ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('cleaning_visit_funding_reservations'))
        ->filter(static fn (array $index): bool => $index['unique'])
        ->map(static fn (array $index): array => $index['columns'])
        ->all();

    expect($indexes)->toContain(['active_visit_id'])
        ->toContain(['reservation_key']);

    $booking = CleaningBooking::factory()->create(['total_price' => 3300]);

    expect((float) $booking->fresh()->getRawOriginal('total_price'))->toBe(3300.0)
        ->and(DB::table('cleaning_visit_funding_reservations')->count())->toBe(0);
});

it('rejects overlapping active visit slots but preserves released reservation history', function (): void {
    $booking = CleaningBooking::factory()->create();
    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    $source = static function (string $key): int {
        return (int) DB::table('cleaning_platform_funding_sources')->insertGetId([
            'provider_key' => 'qa-schema-only',
            'external_credit_id' => $key,
            'owner_scope' => 'cleaning_platform',
            'currency' => 'SYP',
            'gross_amount' => '0.00',
            'verification_state' => 'unverified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $reservation = static function (int $sourceId, int $visitId): array {
        return [
            'reservation_key' => (string) Str::uuid(),
            'funding_source_id' => $sourceId,
            'cleaning_booking_session_id' => $visitId,
            'active_visit_id' => $visitId,
            'quote_evidence_digest' => str_repeat('a', 64),
            'currency' => 'SYP',
            'reserved_amount' => '0.00',
            'reservation_state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    };

    $first = $reservation($source('credit-one'), $visit->id);
    $second = $reservation($source('credit-two'), $visit->id);

    DB::table('cleaning_visit_funding_reservations')->insert($first);

    expect(fn () => DB::table('cleaning_visit_funding_reservations')->insert($second))
        ->toThrow(QueryException::class);

    DB::table('cleaning_visit_funding_reservations')
        ->where('reservation_key', $first['reservation_key'])
        ->update([
            'active_visit_id' => null,
            'released_at' => now(),
            'reservation_state' => 'released',
        ]);

    DB::table('cleaning_visit_funding_reservations')->insert($second);

    expect(DB::table('cleaning_visit_funding_reservations')->count())->toBe(2)
        ->and(DB::table('cleaning_visit_funding_reservations')
            ->whereNotNull('active_visit_id')->count())->toBe(1);
});

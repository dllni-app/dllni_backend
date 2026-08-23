<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

function createCleanupLinkedRows(CleaningBooking $booking): void
{
    $bookingType = $booking->getMorphClass();

    DB::table('disputes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $bookingType,
        'ticket_number' => 'CLEANUP-'.$booking->id.'-'.uniqid(),
        'category' => 'other',
        'status' => 'open',
        'resolution' => null,
        'description' => 'Cleanup command test dispute',
        'worker_earnings_frozen' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sos_alerts')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $bookingType,
        'emergency_type' => 'other',
        'status' => 'triggered',
        'latitude' => null,
        'longitude' => null,
        'triggered_at' => now(),
        'resolved_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('supports dry run without deleting selected cleaning orders or linked data', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    createCleanupLinkedRows($booking);

    $this->artisan('cleaning:delete-orders', [
        '--ids' => [(string) $booking->id],
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(CleaningBooking::query()->whereKey($booking->id)->exists())->toBeTrue();
    expect(DB::table('disputes')->where('booking_id', $booking->id)->where('booking_type', $booking->getMorphClass())->exists())->toBeTrue();
    expect(DB::table('sos_alerts')->where('booking_id', $booking->id)->where('booking_type', $booking->getMorphClass())->exists())->toBeTrue();
});

it('deletes selected cleaning orders with disputes and sos while preserving unselected orders', function (): void {
    $target = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
    ]);
    $untouched = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    createCleanupLinkedRows($target);
    createCleanupLinkedRows($untouched);

    $this->artisan('cleaning:delete-orders', [
        '--ids' => [(string) $target->id],
    ])->assertSuccessful();

    expect(CleaningBooking::query()->whereKey($target->id)->exists())->toBeFalse();
    expect(DB::table('disputes')->where('booking_id', $target->id)->where('booking_type', $target->getMorphClass())->exists())->toBeFalse();
    expect(DB::table('sos_alerts')->where('booking_id', $target->id)->where('booking_type', $target->getMorphClass())->exists())->toBeFalse();

    expect(CleaningBooking::query()->whereKey($untouched->id)->exists())->toBeTrue();
    expect(DB::table('disputes')->where('booking_id', $untouched->id)->where('booking_type', $untouched->getMorphClass())->exists())->toBeTrue();
    expect(DB::table('sos_alerts')->where('booking_id', $untouched->id)->where('booking_type', $untouched->getMorphClass())->exists())->toBeTrue();
});

it('deletes selected cleaning orders by booking code', function (): void {
    $target = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
    ]);
    $untouched = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    createCleanupLinkedRows($target);
    createCleanupLinkedRows($untouched);

    $this->artisan('cleaning:delete-orders', [
        '--codes' => [(string) $target->booking_number],
    ])->assertSuccessful();

    expect(CleaningBooking::query()->whereKey($target->id)->exists())->toBeFalse();
    expect(DB::table('disputes')->where('booking_id', $target->id)->where('booking_type', $target->getMorphClass())->exists())->toBeFalse();
    expect(DB::table('sos_alerts')->where('booking_id', $target->id)->where('booking_type', $target->getMorphClass())->exists())->toBeFalse();

    expect(CleaningBooking::query()->whereKey($untouched->id)->exists())->toBeTrue();
});

it('supports except mode with ids and booking codes', function (): void {
    $keepById = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);
    $keepByCode = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);
    $delete = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);

    createCleanupLinkedRows($keepById);
    createCleanupLinkedRows($keepByCode);
    createCleanupLinkedRows($delete);

    $this->artisan('cleaning:delete-orders', [
        '--except' => true,
        '--ids' => [(string) $keepById->id],
        '--codes' => [(string) $keepByCode->booking_number],
    ])->assertSuccessful();

    expect(CleaningBooking::query()->whereKey($keepById->id)->exists())->toBeTrue();
    expect(CleaningBooking::query()->whereKey($keepByCode->id)->exists())->toBeTrue();
    expect(CleaningBooking::query()->whereKey($delete->id)->exists())->toBeFalse();

    expect(DB::table('disputes')->where('booking_id', $keepById->id)->where('booking_type', $keepById->getMorphClass())->exists())->toBeTrue();
    expect(DB::table('sos_alerts')->where('booking_id', $keepByCode->id)->where('booking_type', $keepByCode->getMorphClass())->exists())->toBeTrue();
    expect(DB::table('disputes')->where('booking_id', $delete->id)->where('booking_type', $delete->getMorphClass())->exists())->toBeFalse();
    expect(DB::table('sos_alerts')->where('booking_id', $delete->id)->where('booking_type', $delete->getMorphClass())->exists())->toBeFalse();
});

it('rejects except mode when a protected selector does not exist', function (): void {
    $booking = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);

    $this->artisan('cleaning:delete-orders', [
        '--except' => true,
        '--codes' => ['CLN-USER-DOES-NOT-EXIST'],
    ])->assertExitCode(2);

    expect(CleaningBooking::query()->whereKey($booking->id)->exists())->toBeTrue();
});

it('supports comma separated ids and all selector validation', function (): void {
    $first = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);
    $second = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);

    $this->artisan('cleaning:delete-orders', [
        '--ids' => [$first->id.','.$second->id],
    ])->assertSuccessful();

    expect(CleaningBooking::query()->whereKey($first->id)->exists())->toBeFalse();
    expect(CleaningBooking::query()->whereKey($second->id)->exists())->toBeFalse();

    $this->artisan('cleaning:delete-orders', [
        '--all' => true,
        '--ids' => ['1'],
    ])->assertExitCode(2);
});

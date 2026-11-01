<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;

it('tracks the active supervisor on the independent booking without a visit', function (): void {
    $booking = CleaningBooking::factory()->create(['booking_kind' => 'standard']);
    $supervisor = User::factory()->create();
    $booking->supervisorAssignments()->create([
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'active_slot' => 1,
    ]);

    expect(app(CleaningSupervisorAssignmentService::class)->activeSupervisorId($booking))->toBe($supervisor->id)
        ->and($booking->sessions()->exists())->toBeFalse();
});

it('records supervisor compensation as a single booking-level liability, distinct from the quoted fee', function (): void {
    $booking = CleaningBooking::factory()->create(['booking_kind' => 'standard']);
    $supervisor = User::factory()->create();
    $payable = [
        'cleaning_booking_id' => $booking->id,
        'supervisor_user_id' => $supervisor->id,
        'amount' => 120,
        'currency' => 'SYP',
        'status' => 'accrued',
        'accrued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('cleaning_supervisor_compensation_payables')->insert($payable);

    expect(DB::table('cleaning_supervisor_compensation_payables')->where('cleaning_booking_id', $booking->id)->first())
        ->toMatchObject(['supervisor_user_id' => $supervisor->id, 'amount' => 120, 'currency' => 'SYP', 'status' => 'accrued']);

    expect(fn () => DB::table('cleaning_supervisor_compensation_payables')->insert($payable))
        ->toThrow(QueryException::class);
});

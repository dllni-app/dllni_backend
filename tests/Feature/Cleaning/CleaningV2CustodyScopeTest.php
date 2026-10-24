<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningEquipmentHandoverService;
use Spatie\Permission\Models\Role;

it('does not let a legacy booking-level supervisor receive initialized V2 visit custody', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'worker_assigned',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
    ]);
    $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'active_slot' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    CleaningBookingMaterialKit::query()->create([
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::READY,
    ]);
    Sanctum::actingAs($supervisor);

    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")->assertNotFound();
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/materials/receive")->assertNotFound();
    $this->assertDatabaseHas('cleaning_booking_material_kits', [
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::READY,
        'received_by_supervisor_user_id' => null,
    ]);
});

it('fails closed for uninitialized V2 worker material receipt and new booking-wide equipment checkout', function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => User::factory()->create(['is_active' => true])->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'worker_id' => $worker->id,
        'status' => 'worker_assigned',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
    ]);
    $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $booking->scheduled_date->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'searching',
        'status' => 'worker_assigned',
    ]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $admin->id,
        'assigned_at' => now(),
        'active_slot' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    CleaningBookingMaterialKit::query()->create([
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::READY,
    ]);
    $asset = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'V2 protected extractor',
        'asset_code' => 'V2-PROTECTED-1',
        'status' => 'available',
        'is_active' => true,
    ]);

    Sanctum::actingAs($worker->user);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/materials/receive")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'CLEANING_V2_VISIT_EXECUTION_UNAVAILABLE');
    $this->assertDatabaseHas('cleaning_booking_material_kits', [
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::READY,
        'received_by_worker_id' => null,
    ]);

    expect(fn () => app(CleaningEquipmentHandoverService::class)
        ->checkoutForBooking($booking, $asset, $admin, 'Must use visit-scoped custody'))
        ->toThrow(ValidationException::class);
    expect($asset->fresh()->status)->toBe('available')
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_id', $booking->id)->count())->toBe(0);
});

it('allows the owning supervisor to report return of equipment already physically held before V2 custody closure', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'worker_assigned',
    ]);
    $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $asset = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Previously issued V2 asset',
        'asset_code' => 'V2-HELD-1',
        'status' => 'in_use',
        'is_active' => true,
    ]);
    $reservation = CleaningEquipmentReservation::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_equipment_id' => $asset->id,
        'supervisor_user_id' => $supervisor->id,
        'reserved_from' => now()->subHour(),
        'reserved_until' => now()->addDay(),
        'status' => 'acknowledged',
        'handed_over_at' => now()->subMinutes(50),
        'acknowledged_at' => now()->subMinutes(45),
    ]);
    Sanctum::actingAs($supervisor);

    $this->postJson("/api/v1/supervisor/equipment-reservations/{$reservation->id}/return")
        ->assertOk()
        ->assertJsonPath('data.reservation.status', 'return_pending_confirmation');
    expect($asset->fresh()->status)->toBe('in_use')
        ->and($reservation->fresh()->returned_at)->not->toBeNull();
});

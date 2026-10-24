<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningAdministrativeOperationsService;
use Modules\Cleaning\Services\CleaningEquipmentHandoverService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
});

function phase3Admin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    return $admin;
}

it('stops the authoritative booking timer and keeps the final bill idempotent with an audit trail', function (): void {
    $admin = phase3Admin();
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'booking_kind' => 'open_time',
        'number_of_workers' => 1,
        'open_time_hourly_rate' => 200,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 15,
        'work_started_at' => now()->subMinutes(72),
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'addons_total' => 0,
        'is_pricing_final' => false,
    ]);
    $service = app(CleaningAdministrativeOperationsService::class);
    $anonymous = User::factory()->create();

    expect(fn () => $service->terminateOpenTime($booking, $anonymous, 'Unauthorized'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->terminateOpenTime($booking, $admin, ''))
        ->toThrow(ValidationException::class);

    $closed = $service->terminateOpenTime($booking, $admin, 'Customer and worker dispute resolved by operations');
    expect($closed->status)->toBe(CleaningBookingStatus::Completed)
        ->and($closed->open_time_terminated_by_id)->toBe($admin->id)
        ->and($closed->open_time_terminated_at)->not->toBeNull()
        ->and($closed->open_time_finalized_at)->not->toBeNull()
        ->and((int) $closed->open_time_billable_minutes)->toBeGreaterThan(0)
        ->and($closed->customer_confirmed_at)->toBeNull();
    $amount = (float) $closed->total_price;
    $time = $closed->work_finished_at?->toISOString();
    $second = $service->terminateOpenTime($booking, $admin, 'Second attempt');
    expect((float) $second->total_price)->toBe($amount)
        ->and($second->work_finished_at?->toISOString())->toBe($time)
        ->and(DB::table('cleaning_operational_action_audits')->where('action', 'admin_terminate_open_time_booking')->count())->toBe(1);
});

it('stops only the selected running open-time session and preserves siblings', function (): void {
    $admin = phase3Admin();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time',
        'status' => CleaningBookingStatus::InProgress,
        'number_of_workers' => 1,
        'open_time_hourly_rate' => 180,
    ]);
    $common = [
        'cleaning_booking_id' => $booking->id,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'hours',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => CleaningBookingSessionStatus::InProgress,
        'base_price' => 360,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 360,
        'pricing_snapshot' => ['hourlyRate' => 180, 'workerCount' => 1, 'minimumBillableMinutes' => 60, 'roundingMinutes' => 15],
    ];
    $first = CleaningBookingSession::query()->create([
        ...$common, 'sequence' => 1, 'scheduled_date' => now()->toDateString(),
        'scheduled_time' => '10:00', 'work_started_at' => now()->subMinutes(71),
    ]);
    $second = CleaningBookingSession::query()->create([
        ...$common, 'sequence' => 2, 'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00', 'work_started_at' => null,
        'status' => CleaningBookingSessionStatus::Scheduled,
    ]);
    $closed = app(CleaningAdministrativeOperationsService::class)->terminateOpenTime($booking, $admin, 'Administrative close', $first);
    expect($closed->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($closed->open_time_terminated_at)->not->toBeNull()
        ->and($closed->open_time_terminated_by_id)->toBe($admin->id)
        ->and($closed->customer_completed_at)->toBeNull()
        ->and($second->fresh()->status)->toBe(CleaningBookingSessionStatus::Scheduled)
        ->and($second->fresh()->work_finished_at)->toBeNull()
        ->and(DB::table('cleaning_operational_action_audits')->where('action', 'admin_terminate_open_time_session')->count())->toBe(1);
});

it('requires handover, worker acknowledgement, return report and final administrative confirmation', function (): void {
    $admin = phase3Admin();
    $worker = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create(['worker_id' => $worker->id]);
    $catalog = CleaningSpecialService::query()->create([
        'name' => 'Equipment test', 'slug' => 'equip-test-'.fake()->unique()->numerify('######'),
        'pricing_unit' => 'piece', 'base_unit_price' => 100, 'is_active' => true,
    ]);
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $catalog->id,
        'service_name' => $catalog->name, 'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal', 'quantity' => 1,
        'base_unit_price' => 100, 'price_multiplier' => 1, 'total_price' => 100,
    ]);
    $asset = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Return check asset', 'status' => 'available', 'is_active' => true,
    ]);
    $reservation = CleaningEquipmentReservation::query()->create([
        'cleaning_booking_special_service_id' => $line->id,
        'cleaning_special_service_equipment_id' => $asset->id,
        'worker_id' => $worker->id,
        'reserved_from' => now()->subHour(),
        'reserved_until' => now()->addHour(),
        'status' => 'reserved',
    ]);
    $service = app(CleaningEquipmentHandoverService::class);
    expect(fn () => $service->confirmReturn($reservation, $admin, 'Not received'))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->handover($reservation, User::factory()->create(), 'Unauthorized'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    Sanctum::actingAs($worker->user);
    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/acknowledge")
        ->assertUnprocessable();

    $service->handover($reservation, $admin, 'Verified handover');
    expect($asset->fresh()->status)->toBe('in_use');
    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/acknowledge")
        ->assertOk()->assertJsonPath('data.reservation.status', 'acknowledged');
    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/return", ['failureReason' => 'Motor stopped'])
        ->assertOk()->assertJsonPath('data.reservation.status', 'failure_pending_confirmation');
    expect($asset->fresh()->status)->toBe('in_use')
        ->and($reservation->fresh()->return_confirmed_at)->toBeNull();
    expect(fn () => $asset->fresh()->update(['status' => 'available']))
        ->toThrow(ValidationException::class);

    $service->confirmReturn($reservation, $admin, 'Damaged equipment moved to maintenance');
    $service->confirmReturn($reservation, $admin, 'Retry');
    expect($reservation->fresh()->status)->toBe('failed')
        ->and($asset->fresh()->status)->toBe('broken')
        ->and($reservation->fresh()->return_confirmed_by_user_id)->toBe($admin->id)
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_equipment_reservation_id', $reservation->id)->count())->toBe(4);
});

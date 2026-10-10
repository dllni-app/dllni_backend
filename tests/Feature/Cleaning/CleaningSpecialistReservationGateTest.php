<?php

declare(strict_types=1);

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningSpecialistEquipmentReservationService;

it('requires explicit approved specialist and independent equipment permissions', function (): void {
    [$worker, $booking, $service, $equipment, $line] = phaseTwoBooking();
    $reservations = app(CleaningSpecialistEquipmentReservationService::class);

    expect(fn () => $reservations->confirmForBooking($booking, $worker))
        ->toThrow(ValidationException::class);
    phaseTwoAuthorize($worker, $service, null);
    expect(fn () => $reservations->confirmForBooking($booking, $worker))
        ->toThrow(ValidationException::class);
    phaseTwoAuthorize($worker, null, $equipment);

    $reservations->confirmForBooking($booking, $worker);
    expect($line->fresh()->assigned_worker_id)->toBe($worker->id)
        ->and(CleaningEquipmentReservation::query()->count())->toBe(1);
    // Repeating confirmation must not duplicate physical assets.
    $reservations->confirmForBooking($booking, $worker);
    expect(CleaningEquipmentReservation::query()->count())->toBe(1);

    DB::table('cleaning_worker_equipment_authorizations')
        ->where('worker_id', $worker->id)->update(['is_active' => false]);
    Sanctum::actingAs($worker->user);
    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertUnprocessable()->assertJsonValidationErrors('equipment');
    expect($line->fresh()->execution_status)->toBe('pending');
});

it('rejects overlapping reservations with buffers even for different bookings', function (): void {
    [$workerA, $bookingA, $service, $equipment, $lineA] = phaseTwoBooking('10:00');
    [$workerB, $bookingB, $unusedService, $unusedEquipment, $lineB] = phaseTwoBooking('11:00', $service, $equipment);
    $equipment->forceFill(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 15])->save();
    phaseTwoAuthorize($workerA, $service, $equipment);
    phaseTwoAuthorize($workerB, $service, $equipment);
    $reservations = app(CleaningSpecialistEquipmentReservationService::class);

    $reservations->confirmForBooking($bookingA, $workerA);
    expect(fn () => $reservations->confirmForBooking($bookingB, $workerB))
        ->toThrow(ValidationException::class);
    expect($lineB->fresh()->assigned_worker_id)->toBeNull()
        ->and(CleaningEquipmentReservation::query()->count())->toBe(1);

    // A cancelled unstarted assignment immediately frees the future slot.
    $reservations->releaseUnstartedForWorker($bookingA, $workerA);
    expect(CleaningEquipmentReservation::query()->first()->status)->toBe('released');
    $reservations->confirmForBooking($bookingB, $workerB);
    expect($lineB->fresh()->assigned_worker_id)->toBe($workerB->id)
        ->and(CleaningEquipmentReservation::query()->where('status', 'reserved')->count())->toBe(1);
});

it('never allocates equipment only when work is started', function (): void {
    [$worker, $booking, $service, $equipment, $line] = phaseTwoBooking();
    phaseTwoAuthorize($worker, $service, $equipment);
    Sanctum::actingAs($worker->user);

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertUnprocessable()->assertJsonValidationErrors('equipment');
    expect(CleaningEquipmentReservation::query()->count())->toBe(0)
        ->and($line->fresh()->execution_status)->toBe('pending');

    app(CleaningSpecialistEquipmentReservationService::class)->confirmForBooking($booking, $worker);
    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertOk()->assertJsonPath('data.specialService.execution_status', 'in_progress');
});

it('can reassign a released reservation to another authorized worker', function (): void {
    [$workerA, $booking, $service, $equipment, $line] = phaseTwoBooking();
    $workerB = Worker::factory()->financiallyEligible()->create();
    phaseTwoAuthorize($workerA, $service, $equipment);
    phaseTwoAuthorize($workerB, $service, $equipment);

    $reservations = app(CleaningSpecialistEquipmentReservationService::class);
    $reservations->confirmForBooking($booking, $workerA);
    $reservations->releaseUnstartedForWorker($booking, $workerA);

    $booking->forceFill(['worker_id' => $workerB->id])->save();
    $reservations->confirmForBooking($booking, $workerB);

    expect($line->fresh()->assigned_worker_id)->toBe($workerB->id)
        ->and(CleaningEquipmentReservation::query()->count())->toBe(1)
        ->and(CleaningEquipmentReservation::query()->first()->worker_id)->toBe($workerB->id)
        ->and(CleaningEquipmentReservation::query()->first()->status)->toBe('reserved');
});

it('releases only one visit equipment slot while preserving sibling visits', function (): void {
    [$worker, $booking, $service, $equipment, $line] = phaseTwoBooking();
    phaseTwoAuthorize($worker, $service, $equipment);
    $sessions = [];
    foreach ([1, 2] as $sequence) {
        $sessions[] = \Modules\Cleaning\Models\CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => 'recurring_cleaning',
            'calculation_mode' => 'estimated_hours',
            'scheduled_date' => now()->addDays($sequence + 1)->toDateString(),
            'scheduled_time' => '10:00',
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => 'fully_covered',
            'status' => 'worker_assigned',
            'base_price' => 100,
            'addons_total' => 0,
            'materials_total' => 0,
            'special_services_total' => 100,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'extension_fee_total' => 0,
            'cancellation_fee' => 0,
            'total_price' => 200,
        ]);
    }
    $line->sessions()->sync([$sessions[0]->id, $sessions[1]->id]);
    $reservations = app(CleaningSpecialistEquipmentReservationService::class);
    foreach ($sessions as $session) {
        $reservations->confirmForSession($booking, $session, $worker);
    }
    expect(CleaningEquipmentReservation::query()->where('status', 'reserved')->count())->toBe(2);
    $reservations->releaseForSessionWorker($booking, $sessions[0], $worker);
    expect(CleaningEquipmentReservation::query()->where('status', 'reserved')->count())->toBe(1)
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_session_id', $sessions[0]->id)->first()->status)->toBe('released')
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_session_id', $sessions[1]->id)->first()->status)->toBe('reserved');
});

function phaseTwoAuthorize(
    Worker $worker,
    ?CleaningSpecialService $service,
    ?CleaningSpecialServiceEquipment $equipment,
): void {
    if ($service !== null) {
        DB::table('cleaning_worker_special_service_skills')->insert([
            'worker_id' => $worker->id, 'cleaning_special_service_id' => $service->id,
            'is_active' => true, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    if ($equipment !== null) {
        DB::table('cleaning_worker_equipment_authorizations')->insert([
            'worker_id' => $worker->id, 'cleaning_special_service_equipment_id' => $equipment->id,
            'is_active' => true, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/** @return array{Worker,CleaningBooking,CleaningSpecialService,CleaningSpecialServiceEquipment,CleaningBookingSpecialService} */
function phaseTwoBooking(
    string $time = '10:00',
    ?CleaningSpecialService $reuseService = null,
    ?CleaningSpecialServiceEquipment $reuseEquipment = null,
): array {
    $worker = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => $time,
    ]);
    $service = $reuseService ?? CleaningSpecialService::query()->create([
        'name' => 'Phase 2 sofa',
        'slug' => 'phase-two-'.fake()->unique()->numerify('########'),
        'pricing_unit' => 'piece',
        'base_unit_price' => 200,
        'estimated_duration_minutes' => 60,
        'is_active' => true,
    ]);
    $equipment = $reuseEquipment ?? CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Phase 2 extractor',
        'asset_code' => 'P2-'.fake()->unique()->numerify('########'),
        'status' => 'available', 'is_active' => true,
    ]);
    if ($reuseService === null) {
        $service->equipment()->attach($equipment->id);
    }
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $service->id,
        'service_name' => $service->name,
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => 1,
        'base_unit_price' => 200,
        'price_multiplier' => 1,
        'total_price' => 200,
        'execution_status' => 'pending',
    ]);
    return [$worker, $booking, $service, $equipment, $line];
}

<?php

declare(strict_types=1);

use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

it('keeps material service and equipment operations idempotent through worker HTTP actions', function (): void {
    $worker = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);
    $kit = CleaningBookingMaterialKit::query()->create([
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::READY,
        'prepared_at' => now()->subHour(),
    ]);
    $catalogService = CleaningSpecialService::query()->create([
        'name' => 'Idempotent sofa service',
        'slug' => 'idempotent-sofa-'.fake()->unique()->numerify('####'),
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'estimated_duration_minutes' => 45,
        'is_active' => true,
    ]);
    $equipment = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Idempotent extractor',
        'asset_code' => 'EX-'.fake()->unique()->numerify('####'),
        'status' => 'available',
        'is_active' => true,
    ]);
    $catalogService->equipment()->attach($equipment->id);
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $catalogService->id,
        'service_name' => $catalogService->name,
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => 1,
        'base_unit_price' => 100,
        'price_multiplier' => 1,
        'total_price' => 100,
        'equipment_snapshot' => [['id' => $equipment->id, 'name' => $equipment->name]],
        'execution_status' => 'pending',
    ]);

    Sanctum::actingAs($worker->user);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/materials/receive")
        ->assertOk()
        ->assertJsonPath('data.materialKit.status', CleaningBookingMaterialKit::RECEIVED);
    $receivedAt = $kit->fresh()->received_at?->toISOString();

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/materials/receive")
        ->assertOk()
        ->assertJsonPath('data.materialKit.status', CleaningBookingMaterialKit::RECEIVED);
    expect($kit->fresh()->received_at?->toISOString())->toBe($receivedAt);

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertOk()
        ->assertJsonPath('data.specialService.execution_status', 'in_progress');
    $startedAt = $line->fresh()->started_at?->toISOString();

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertOk()
        ->assertJsonPath('data.specialService.execution_status', 'in_progress');
    expect($line->fresh()->started_at?->toISOString())->toBe($startedAt)
        ->and(CleaningEquipmentReservation::query()->count())->toBe(1);

    $reservation = CleaningEquipmentReservation::query()->sole();
    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.reservation.status', 'acknowledged');
    $acknowledgedAt = $reservation->fresh()->acknowledged_at?->toISOString();

    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.reservation.status', 'acknowledged');
    expect($reservation->fresh()->acknowledged_at?->toISOString())->toBe($acknowledgedAt);

    $finishPayload = ['status' => 'completed', 'afterImages' => ['cleaning/after/sofa.jpg']];
    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/finish", $finishPayload)
        ->assertOk()
        ->assertJsonPath('data.specialService.execution_status', 'completed');
    $completedAt = $line->fresh()->completed_at?->toISOString();

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/finish", $finishPayload)
        ->assertOk()
        ->assertJsonPath('data.specialService.execution_status', 'completed');
    expect($line->fresh()->completed_at?->toISOString())->toBe($completedAt);

    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/return")
        ->assertOk()
        ->assertJsonPath('data.reservation.status', 'returned');
    $returnedAt = $reservation->fresh()->returned_at?->toISOString();

    $this->postJson("/api/v1/cleaning-equipment-reservations/{$reservation->id}/return")
        ->assertOk()
        ->assertJsonPath('data.reservation.status', 'returned');

    expect($reservation->fresh()->returned_at?->toISOString())->toBe($returnedAt)
        ->and($equipment->fresh()->status)->toBe('available');
});

it('does not allow a terminal operational result to be rewritten', function (): void {
    $worker = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create(['worker_id' => $worker->id]);
    $catalogService = CleaningSpecialService::query()->create([
        'name' => 'Terminal service',
        'slug' => 'terminal-service-'.fake()->unique()->numerify('####'),
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'is_active' => true,
    ]);
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $catalogService->id,
        'assigned_worker_id' => $worker->id,
        'service_name' => $catalogService->name,
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => 1,
        'base_unit_price' => 100,
        'price_multiplier' => 1,
        'total_price' => 100,
        'equipment_snapshot' => [],
        'execution_status' => 'completed',
        'completed_at' => now(),
    ]);

    Sanctum::actingAs($worker->user);

    $this->postJson(
        "/api/v1/cleaning-booking-special-services/{$line->id}/finish",
        ['status' => 'unable', 'reason' => 'Must not replace completion'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service');

    expect($line->fresh()->execution_status)->toBe('completed');
});

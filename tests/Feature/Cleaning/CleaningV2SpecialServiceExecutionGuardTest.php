<?php

declare(strict_types=1);

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningSpecialService;

function b1SpecialServiceLine(int $workerCount): array
{
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => $workerCount,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => $workerCount,
        'coverage_status' => 'searching',
        'status' => 'worker_assigned',
    ]);
    $service = CleaningSpecialService::query()->create([
        'name' => 'B1 no-equipment service '.$workerCount,
        'slug' => 'b1-no-equipment-'.$workerCount.'-'.fake()->unique()->numerify('####'),
        'pricing_unit' => 'piece',
        'base_unit_price' => 25,
        'estimated_duration_minutes' => 30,
        'is_active' => true,
    ]);
    DB::table('cleaning_worker_special_service_skills')->insert([
        'worker_id' => $worker->id,
        'cleaning_special_service_id' => $service->id,
        'is_active' => true,
        'approved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $service->id,
        'service_name' => $service->name,
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => 1,
        'base_unit_price' => 25,
        'price_multiplier' => 1,
        'total_price' => 25,
        'equipment_snapshot' => [],
        'execution_status' => 'pending',
    ]);

    return [$booking, $line, $worker];
}

it('denies resource-scoped start and finish for V2 operational groups before mutation', function (): void {
    [, $line, $worker] = b1SpecialServiceLine(2);
    Sanctum::actingAs($worker->user);

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'CLEANING_V2_VISIT_EXECUTION_UNAVAILABLE');
    expect($line->fresh()->execution_status)->toBe('pending')
        ->and($line->fresh()->assigned_worker_id)->toBeNull()
        ->and($line->fresh()->started_at)->toBeNull();

    $line->forceFill([
        'assigned_worker_id' => $worker->id,
        'execution_status' => 'in_progress',
        'started_at' => now(),
    ])->save();
    $startedAt = $line->fresh()->started_at?->toISOString();

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/finish", [
        'status' => 'completed',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'CLEANING_V2_VISIT_EXECUTION_UNAVAILABLE');
    expect($line->fresh()->execution_status)->toBe('in_progress')
        ->and($line->fresh()->started_at?->toISOString())->toBe($startedAt)
        ->and($line->fresh()->completed_at)->toBeNull();
});

it('preserves the existing single-worker no-equipment special-service workflow', function (): void {
    [, $line, $worker] = b1SpecialServiceLine(1);
    Sanctum::actingAs($worker->user);

    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/start")
        ->assertOk()
        ->assertJsonPath('data.specialService.execution_status', 'in_progress');
    $this->postJson("/api/v1/cleaning-booking-special-services/{$line->id}/finish", [
        'status' => 'completed',
    ])->assertOk()
        ->assertJsonPath('data.specialService.execution_status', 'completed');
});

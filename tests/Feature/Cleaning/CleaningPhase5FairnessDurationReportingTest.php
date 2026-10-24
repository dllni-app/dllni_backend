<?php

declare(strict_types=1);

use App\Models\Worker;
use App\Models\User;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Services\CleaningSpecialistFairnessRankingService;
use Modules\Cleaning\Services\CleaningSpecialServiceDurationPlanningService;
use Modules\Cleaning\Services\CleaningSpecialOperationsReportService;
use Illuminate\Support\Facades\DB;

function phase5Service(string $slug, int $minutes = 60): CleaningSpecialService
{
    return CleaningSpecialService::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'estimated_duration_minutes' => $minutes,
        'is_active' => true,
    ]);
}

function phase5Line(CleaningBooking $booking, CleaningSpecialService $service, ?Worker $worker, float $quantity): CleaningBookingSpecialService
{
    return CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $service->id,
        'assigned_worker_id' => $worker?->id,
        'service_name' => $service->name,
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => $quantity,
        'base_unit_price' => 100,
        'price_multiplier' => 1,
        'total_price' => 100 * $quantity,
        'execution_status' => 'pending',
    ]);
}

it('ranks previously unallocated eligible specialists before recently allocated workers while remaining deterministic', function (): void {
    config()->set('cleaning_specialist_planning.fairness_enabled', true);
    config()->set('cleaning_specialist_planning.fairness_lookback_days', 30);
    $older = Worker::factory()->financiallyEligible()->create(['average_rating' => 5]);
    $newer = Worker::factory()->financiallyEligible()->create(['average_rating' => 1]);
    $booking = CleaningBooking::factory()->create(['status' => 'pending']);
    $service = phase5Service('fairness-test');
    phase5Line($booking, $service, $older, 1);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $older->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now()->subHours(6),
    ]);

    $ranking = app(CleaningSpecialistFairnessRankingService::class);
    expect($ranking->rank(collect([$older, $newer]))->pluck('id')->all())
        ->toBe([$newer->id, $older->id]);

    config()->set('cleaning_specialist_planning.fairness_lookback_days', 1);
    $sorted = $ranking->rank(collect([$older, $newer]))->pluck('id')->all();
    expect($sorted)->toBe([$newer->id, $older->id]);
    config()->set('cleaning_specialist_planning.fairness_enabled', false);
    expect($ranking->rank(collect([$older, $newer]))->pluck('id')->all())
        ->toBe([$older->id, $newer->id]);
});

it('sums sequential work for the same worker and parallels distinct workers with conservative unassigned tasks', function (): void {
    $booking = CleaningBooking::factory()->create();
    $workerA = Worker::factory()->create();
    $workerB = Worker::factory()->create();
    phase5Line($booking, phase5Service('phase5-a', 60), $workerA, 1);
    phase5Line($booking, phase5Service('phase5-b', 45), $workerA, 2);
    phase5Line($booking, phase5Service('phase5-c', 120), $workerB, 1);
    phase5Line($booking, phase5Service('phase5-d', 30), null, 1);

    $plan = app(CleaningSpecialServiceDurationPlanningService::class)->forBooking($booking);
    expect($plan['totalWorkMinutes'])->toBe(300)
        ->and($plan['maxSingleSessionMinutes'])->toBe(180)
        ->and($plan['sessionPlans'][0]['parallelWorkerCount'])->toBe(2)
        ->and($plan['sessionPlans'][0]['unassignedWorkMinutes'])->toBe(30)
        ->and($plan['sessionPlans'][0]['workerLoadsMinutes'][$workerA->id])->toBe(150)
        ->and($plan['sessionPlans'][0]['workerLoadsMinutes'][$workerB->id])->toBe(120);
});

it('reconciles specialized service line prices with item snapshots and reports maintenance and accepted opportunity counts', function (): void {
    $worker = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create();
    $service = phase5Service('report-test');
    $line = phase5Line($booking, $service, $worker, 3);
    CleaningBookingSpecialServiceItem::query()->create([
        'cleaning_booking_special_service_id' => $line->id,
        'quantity' => 3, 'base_unit_price' => 100, 'price_multiplier' => 1,
        'total_price' => 300, 'dirtiness_level' => 'normal',
    ]);
    DB::table('cleaning_worker_special_service_skills')->insert([
        'worker_id' => $worker->id,
        'cleaning_special_service_id' => $service->id,
        'is_active' => true, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id, 'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value, 'accepted_at' => now(),
    ]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => NewOrderRequestNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $worker->user_id,
        'data' => json_encode(['bookingId' => $booking->id], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $equipment = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Needs repair', 'status' => 'broken', 'is_active' => true,
    ]);
    CleaningEquipmentReservation::query()->create([
        'cleaning_special_service_equipment_id' => $equipment->id,
        'cleaning_booking_special_service_id' => $line->id,
        'worker_id' => $worker->id,
        'reserved_from' => now()->subHour(),
        'reserved_until' => now()->addHour(),
        'status' => 'failure_pending_confirmation',
    ]);

    $report = app(CleaningSpecialOperationsReportService::class)->overview();
    expect($report['financial']['recordedSpecialServiceCharges'])->toBe(300.0)
        ->and($report['financial']['itemizedChargeTotal'])->toBe(300.0)
        ->and($report['financial']['lineItemReconciliationDifference'])->toBe(0.0)
        ->and($report['equipment']['maintenanceOrBrokenAssets'])->toBe(1)
        ->and($report['equipment']['pendingAdministrativeReturns'])->toBe(1)
        ->and($report['workerOpportunities']['recentAcceptedWorkBySpecialist'][0]['recentAcceptedSlots'])->toBe(1)
        ->and($report['workerOpportunities']['recentAcceptedWorkBySpecialist'][0]['recordedSpecialistOffers'])->toBe(1)
        ->and($report['equipment']['maintenanceAssets'][0]['name'])->toBe('Needs repair');
});

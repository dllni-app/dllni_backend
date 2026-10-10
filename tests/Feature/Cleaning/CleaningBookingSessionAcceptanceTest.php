<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionAcceptanceService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0.00,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0.00,
            'travel_per_km' => 0.00,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

it('does not silently partially accept accept-all when selected sessions overlap', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();

    $first = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 2.0);
    $second = makeExecutionSession($booking, 2, now()->addDay()->toDateString(), '11:00', 2.0);

    $result = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptAllAvailableSessions($booking, $worker);

    expect($result['allAccepted'])->toBeFalse()
        ->and($result['acceptedSessionIds'])->toBe([])
        ->and(collect($result['rejected'])->pluck('reasonCode')->all())
        ->toContain('selected_sessions_overlap')
        ->and($first->workerAssignments()->count())->toBe(0)
        ->and($second->workerAssignments()->count())->toBe(0);
});

it('keeps the final worker seat exclusive and leaves no over-assignment', function (): void {
    $firstWorker = makeSessionWorker();
    $secondWorker = makeSessionWorker();
    $booking = makeSessionBooking();
    $session = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 2.0, 1);

    $firstResult = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $firstWorker, [$session->id]);

    $secondResult = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $secondWorker, [$session->id]);

    expect($firstResult['acceptedSessionIds'])->toBe([(int) $session->id])
        ->and($secondResult['acceptedSessionIds'])->toBe([])
        ->and(collect($secondResult['rejected'])->pluck('reasonCode')->all())
        ->toContain('session_fully_covered')
        ->and($session->workerAssignments()->whereIn('status', [
            'accepted',
            'accepted_waiting_for_order_start',
            'awaiting_start_verification',
            'start_approved',
            'in_progress',
            'awaiting_customer_completion',
            'time_extension_requested',
            'completed',
        ])->count())->toBe(1)
        ->and($session->fresh()->coverage_status->value)->toBe('fully_covered');
});

it('accepts only the explicitly selected sessions and keeps the others available', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();

    $first = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 1.0);
    $second = makeExecutionSession($booking, 2, now()->addDays(2)->toDateString(), '10:00', 1.0);
    $third = makeExecutionSession($booking, 3, now()->addDays(3)->toDateString(), '10:00', 1.0);

    $result = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $worker, [$first->id, $third->id]);

    expect($result['rejected'])->toBe([])
        ->and($result['acceptedSessionIds'])->toBe([(int) $first->id, (int) $third->id])
        ->and($first->fresh()->coverage_status->value)->toBe('fully_covered')
        ->and($second->fresh()->coverage_status->value)->toBe('searching')
        ->and($third->fresh()->coverage_status->value)->toBe('fully_covered')
        ->and($second->workerAssignments()->count())->toBe(0);
});

it('rejects partial acceptance for a multi-day event through the public API', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking('event_assistance');
    $first = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 1.0);
    makeExecutionSession($booking, 2, now()->addDays(2)->toDateString(), '10:00', 1.0);

    Sanctum::actingAs($worker->user);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => [$first->id]],
    )
        ->assertUnprocessable()
        ->assertJsonPath('data.acceptance.allAccepted', false)
        ->assertJsonPath('data.acceptance.acceptedSessionIds', [])
        ->assertJsonPath('data.acceptance.rejected.0.reasonCode', 'event_all_days_required');

    expect($first->workerAssignments()->count())->toBe(0);
});


it('reports session acceptance as the current worker order status while the parent stays pending', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();

    makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 1.0);
    makeExecutionSession($booking, 2, now()->addDays(2)->toDateString(), '10:00', 1.0);
    makeExecutionSession($booking, 3, now()->addDays(3)->toDateString(), '10:00', 1.0);

    Sanctum::actingAs($worker->user);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-all")
        ->assertOk()
        ->assertJsonPath('data.acceptance.allAccepted', true);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::Pending->value)
        ->assertJsonPath('data.globalStatus', CleaningBookingStatus::Pending->value)
        ->assertJsonPath(
            'data.worker_order_status',
            'accepted_waiting_for_order_start',
        );
});

it('reserves specialist equipment during the session acceptance transaction', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();
    $session = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 2.0);
    $service = CleaningSpecialService::query()->create([
        'name' => 'Specialist equipment acceptance',
        'slug' => 'specialist-accept-'.fake()->unique()->numerify('######'),
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'estimated_duration_minutes' => 40,
        'is_active' => true,
    ]);
    $equipment = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Specialist accepted asset',
        'status' => 'available',
        'buffer_before_minutes' => 10,
        'buffer_after_minutes' => 10,
        'is_active' => true,
    ]);
    $service->equipment()->attach($equipment->id);
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_special_service_id' => $service->id,
        'service_name' => $service->name,
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => 1,
        'base_unit_price' => 100,
        'price_multiplier' => 1,
        'total_price' => 100,
    ]);

    $acceptance = app(CleaningBookingSessionAcceptanceService::class);
    $denied = $acceptance->acceptSelectedSessions($booking, $worker, [$session->id]);
    expect(collect($denied['rejected'])->pluck('reasonCode'))->toContain('special_service_skill_missing')
        ->and($session->workerAssignments()->count())->toBe(0);

    $stamp = now();
    DB::table('cleaning_worker_special_service_skills')->insert([
        'worker_id' => $worker->id, 'cleaning_special_service_id' => $service->id,
        'is_active' => true, 'approved_at' => $stamp,
        'created_at' => $stamp, 'updated_at' => $stamp,
    ]);
    $denied = $acceptance->acceptSelectedSessions($booking, $worker, [$session->id]);
    expect(collect($denied['rejected'])->pluck('reasonCode'))->toContain('special_equipment_authorization_missing')
        ->and($session->workerAssignments()->count())->toBe(0);

    DB::table('cleaning_worker_equipment_authorizations')->insert([
        'worker_id' => $worker->id, 'cleaning_special_service_equipment_id' => $equipment->id,
        'is_active' => true, 'approved_at' => $stamp,
        'created_at' => $stamp, 'updated_at' => $stamp,
    ]);
    $accepted = $acceptance->acceptSelectedSessions($booking, $worker, [$session->id]);
    expect($accepted['acceptedSessionIds'])->toBe([$session->id])
        ->and($line->fresh()->assigned_worker_id)->toBe($worker->id)
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_session_id', $session->id)->count())->toBe(1);
});

it('retains separate equipment windows for special services covering multiple sessions', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();
    $first = makeExecutionSession($booking, 1, now()->addDays(2)->toDateString(), '10:00', 2.0);
    $second = makeExecutionSession($booking, 2, now()->addDays(3)->toDateString(), '10:00', 2.0);
    $service = CleaningSpecialService::query()->create([
        'name' => 'Multi-visit special service',
        'slug' => 'multi-visit-'.fake()->unique()->numerify('######'),
        'pricing_unit' => 'piece', 'base_unit_price' => 100,
        'estimated_duration_minutes' => 60, 'is_active' => true,
    ]);
    $equipment = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Multi-visit extractor', 'status' => 'available', 'is_active' => true,
    ]);
    $service->equipment()->attach($equipment->id);
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $service->id,
        'service_name' => $service->name,
        'pricing_unit' => 'piece', 'dirtiness_level' => 'normal',
        'quantity' => 1, 'base_unit_price' => 100,
        'price_multiplier' => 1, 'total_price' => 100,
    ]);
    $line->sessions()->sync([$first->id, $second->id]);
    $stamp = now();
    DB::table('cleaning_worker_special_service_skills')->insert([
        'worker_id' => $worker->id, 'cleaning_special_service_id' => $service->id,
        'is_active' => true, 'approved_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
    ]);
    DB::table('cleaning_worker_equipment_authorizations')->insert([
        'worker_id' => $worker->id, 'cleaning_special_service_equipment_id' => $equipment->id,
        'is_active' => true, 'approved_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
    ]);

    $result = app(CleaningBookingSessionAcceptanceService::class)->acceptAllAvailableSessions($booking, $worker);

    expect($result['allAccepted'])->toBeTrue()
        ->and($result['acceptedSessionIds'])->toBe([$first->id, $second->id])
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_special_service_id', $line->id)->count())->toBe(2)
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_session_id', $first->id)->count())->toBe(1)
        ->and(CleaningEquipmentReservation::query()->where('cleaning_booking_session_id', $second->id)->count())->toBe(1);
});

function makeSessionWorker(): Worker
{
    $workingHours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $workingHours[$day] = [
            'available' => true,
            'data' => [['00:00' => '23:59']],
        ];
    }

    $user = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'gender' => 'male',
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
        'default_working_hours' => $workingHours,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 100000,
            'debt_balance' => 0,
            'deposited_total' => 100000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 100000,
            'is_active' => true,
        ],
    );

    return $worker->fresh(['user', 'deposit']);
}

function makeSessionBooking(string $propertyType = 'villa'): CleaningBooking
{
    return CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'base_price' => 3000,
        'addons_total' => 0,
        'property_type' => $propertyType,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'gender_preference' => 'any',
        'neighborhood_id' => null,
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);
}

function makeExecutionSession(
    CleaningBooking $booking,
    int $sequence,
    string $date,
    string $time,
    float $hours,
    int $requiredWorkers = 1,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'duration_hours' => $hours,
        'required_workers' => $requiredWorkers,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'base_price' => 3000,
        'admin_margin_amount' => 300,
        'total_price' => 3300,
        'is_pricing_final' => false,
    ]);
}

<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV2VisitTaskAllocationService;
use Spatie\Permission\Models\Role;

function v2WorkSegmentFixture(): array
{
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time', 'number_of_workers' => 2,
        'status' => 'worker_assigned', 'is_pricing_final' => true,
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1, 'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'actual_time',
        'scheduled_date' => now()->toDateString(), 'scheduled_time' => '09:00',
        'duration_hours' => 3, 'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(), 'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned', 'is_pricing_final' => true,
        'open_time_expected_max_minutes' => 180,
        'pricing_snapshot' => ['scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => 2, 'expectedMaxMinutes' => 180],
    ]);
    $owner = $session->supervisorAssignments()->create([
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id, 'assigned_at' => now(),
        'compensation_hourly_rate' => 100, 'active_slot' => 1,
    ]);
    $workers = [];
    foreach ([1, 2] as $_) {
        $worker = Worker::factory()->create();
        $workers[] = $worker;
        $assignment = $session->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => 'accepted_waiting_for_order_start',
            'accepted_at' => now()->subHour(),
            'started_travel_at' => now()->subMinutes(35),
            'arrived_at' => now()->subMinutes(5),
            'service_share_amount' => 450, 'travel_fee' => 10,
            'worker_amount' => 460,
        ]);
        $session->workerAttestations()->create([
            'cleaning_booking_session_worker_assignment_id' => $assignment->id,
            'cleaning_booking_session_supervisor_assignment_id' => $owner->id,
            'recorded_by_user_id' => $supervisor->id,
            'outcome' => 'present', 'certified_at' => now(),
        ]);
    }
    app(CleaningV2VisitTaskAllocationService::class)->allocate(
        $supervisor, $booking, $session,
        [['taskKey' => 'visit_scope', 'workerIds' => array_map(static fn ($worker) => $worker->id, $workers)]],
    );
    $session->supervisorAttendances()->create([
        'cleaning_booking_session_supervisor_assignment_id' => $owner->id,
        'started_travel_at' => now()->subMinutes(40),
        'arrived_at' => now()->subMinutes(5),
        'last_actor_user_id' => $supervisor->id,
    ]);
    $session->executionVerifications()->create([
        'cleaning_booking_session_supervisor_assignment_id' => $owner->id,
        'customer_user_id' => $booking->customer_id,
        'status' => 'verified', 'expires_at' => now()->addMinutes(10),
        'verified_at' => now()->subMinute(), 'consumed_at' => now(),
        'active_slot' => 1,
    ]);

    return [$supervisor, $booking, $session, $workers];
}

it('denies supervisor and worker V2 work-segment creation without an approved funded customer quote', function (): void {
    [$supervisor, $booking, $session, $workers] = v2WorkSegmentFixture();
    expect(app(Modules\Cleaning\Services\SupervisorScheduledV2Service::class)->isEligible($booking))->toBeTrue();
    expect(app(Modules\Cleaning\Services\SupervisorScheduledV2Service::class)->assignedQuery($supervisor)->whereKey($booking->id)->exists())->toBeTrue();
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/start";
    Sanctum::actingAs($supervisor);
    $this->postJson($url)->assertUnprocessable()->assertJsonPath(
        'errors.execution.0', 'approved_funding_source_unproven',
    );
    $this->postJson($url)->assertUnprocessable();
    expect($session->supervisorWorkSegments()->count())->toBe(0)
        ->and($session->fresh()->work_started_at)->toBeNull();

    Sanctum::actingAs($workers[0]->user);
    $this->postJson("/api/v1/cleaning/v2/bookings/{$booking->id}/visits/1/tasks/start", [
        'taskKey' => 'visit_scope',
    ])->assertUnprocessable();
    expect($session->executionTasks()->firstOrFail()->workers()->firstOrFail()->segments()->count())->toBe(0)
        ->and($session->fresh()->payment_settled_at)->toBeNull();
});

it('prevents an unrelated supervisor and a customer from controlling V2 work while preserving the visit', function (): void {
    [$supervisor, $booking, $session] = v2WorkSegmentFixture();
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->postJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/start")->assertNotFound();
    $this->postJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/finish")->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->postJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/start")->assertForbidden();
    expect($session->fresh()->work_started_at)->toBeNull()
        ->and($session->fresh()->work_finished_at)->toBeNull()
        ->and($session->supervisorWorkSegments()->count())->toBe(0);
});

it('rejects duplicate or forged worker task keys without mutating worker segments or payment', function (): void {
    [$supervisor, $booking, $session, $workers] = v2WorkSegmentFixture();
    Sanctum::actingAs($workers[0]->user);
    $url = "/api/v1/cleaning/v2/bookings/{$booking->id}/visits/1/tasks/start";
    $this->postJson($url, ['taskKey' => ''])->assertUnprocessable();
    $this->postJson($url, ['taskKey' => 'unknown_task'])->assertUnprocessable();
    $this->postJson("/api/v1/cleaning/v2/bookings/{$booking->id}/visits/1/tasks/finish", [
        'taskKey' => 'visit_scope',
    ])->assertUnprocessable();
    expect($session->fresh()->payment_status)->not->toBe('settled')
        ->and($session->fresh()->work_started_at)->toBeNull()
        ->and($session->supervisorWorkSegments()->count())->toBe(0);
});

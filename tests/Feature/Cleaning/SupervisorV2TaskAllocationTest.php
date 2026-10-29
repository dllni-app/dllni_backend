<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Services\CleaningV2VisitExecutionCapabilityService;
use Spatie\Permission\Models\Role;

function phase28TaskFixture(int $workers = 2): array
{
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time',
        'number_of_workers' => $workers,
        'status' => 'worker_assigned',
        'is_pricing_final' => true,
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'actual_time',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 3,
        'required_workers' => $workers,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned',
        'is_pricing_final' => true,
        'open_time_expected_max_minutes' => 180,
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => $workers,
            'expectedMaxMinutes' => 180,
        ],
    ]);
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 20000,
        'active_slot' => 1,
    ]);
    $workerIds = [];
    for ($i = 0; $i < $workers; $i++) {
        $worker = Worker::factory()->create();
        $workerIds[] = $worker->id;
        $session->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => 'accepted_waiting_for_order_start',
            'accepted_at' => now(),
        ]);
    }

    return [$supervisor, $booking, $session, $workerIds];
}

it('records explicit visit task-worker memberships and proves G2B readiness while execution stays disabled', function (): void {
    [$supervisor, $booking, $session, $workers] = phase28TaskFixture();
    Sanctum::actingAs($supervisor);
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/task-assignments";
    $payload = ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => $workers]]];
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.executionEnabled', false)
        ->assertJsonPath('data.tasks.0.taskKey', 'visit_scope');

    expect($session->executionTasks()->count())->toBe(1)
        ->and($session->executionTasks()->first()->workers()->count())->toBe(2);
    $readiness = app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking->fresh(), $session->fresh());
    expect($readiness['reasonCodes'])->not->toContain('task_worker_membership_unproven')
        ->and($readiness['reasonCodes'])->toContain('execution_path_not_enabled')
        ->and($readiness['executionCapability'])->toBeFalse()
        ->and($readiness['allowedActions'])->toBe([]);

    // Idempotency never produces another row.
    $this->postJson($url, $payload)->assertOk();
    expect($session->executionTasks()->first()->workers()->count())->toBe(2);
});

it('rejects unknown workers, incomplete membership and changes after allocation', function (): void {
    [$supervisor, $booking, $session, $workers] = phase28TaskFixture();
    Sanctum::actingAs($supervisor);
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/task-assignments";
    $this->postJson($url, ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => [$workers[0], 999999]]]])
        ->assertUnprocessable();
    $this->postJson($url, ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => [$workers[0]]]]])
        ->assertUnprocessable();
    expect($session->executionTasks()->count())->toBe(0);

    $this->postJson($url, ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => $workers]]])->assertOk();
    $session->workerAssignments()->where('worker_id', $workers[1])->update(['released_at' => now()]);
    $this->postJson($url, ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => $workers]]])
        ->assertUnprocessable();
    expect(app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking->fresh(), $session->fresh())['reasonCodes'])
        ->toContain('task_worker_membership_unproven');
});

it('rejects non-owner supervisor and customers without leaking task details', function (): void {
    [$supervisor, $booking, $session, $workers] = phase28TaskFixture();
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/task-assignments";
    $payload = ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => $workers]]];

    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->postJson($url, $payload)->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->postJson($url, $payload)->assertForbidden();
    expect($session->executionTasks()->count())->toBe(0);
});

it('rejects allocation when team coverage is incomplete or visit is terminal', function (): void {
    [$supervisor, $booking, $session, $workers] = phase28TaskFixture();
    Sanctum::actingAs($supervisor);
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/task-assignments";
    $payload = ['tasks' => [['taskKey' => 'visit_scope', 'workerIds' => $workers]]];

    $session->workerAssignments()->where('worker_id', $workers[1])->update(['status' => 'pending', 'accepted_at' => null]);
    $this->postJson($url, $payload)->assertUnprocessable();
    expect($session->executionTasks()->count())->toBe(0);

    $session->forceFill(['status' => 'completed'])->save();
    $this->postJson($url, $payload)->assertUnprocessable();
    expect($session->executionTasks()->count())->toBe(0);
});

it('records supervisor travel and arrival as separate idempotent visit evidence', function (): void {
    [$supervisor, $booking, $session] = phase28TaskFixture();
    Sanctum::actingAs($supervisor);
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/supervisor";

    $this->postJson("$base/arrive")->assertUnprocessable();
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")
        ->assertOk()
        ->assertJsonPath('data.supervisorAttendance.allowedActions.0', 'start_travel')
        ->assertJsonPath('data.attendance.arrivedWorkers', 0);
    expect($session->supervisorAttendances()->count())->toBe(0);
    $this->postJson("$base/start-travel")->assertOk()->assertJsonPath('data.attendance.version', 1);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")
        ->assertJsonPath('data.supervisorAttendance.allowedActions.0', 'arrive');
    $this->postJson("$base/start-travel")->assertOk()->assertJsonPath('data.attendance.version', 1);
    $this->postJson("$base/arrive")->assertOk()->assertJsonPath('data.attendance.version', 2);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")
        ->assertJsonPath('data.supervisorAttendance.allowedActions', [])
        ->assertJsonPath('data.attendance.arrivedWorkers', 0);
    $this->postJson("$base/arrive")->assertOk()->assertJsonPath('data.attendance.version', 2);
    expect($session->supervisorAttendances()->count())->toBe(1)
        ->and($session->supervisorAttendances()->first()->arrived_at)->not->toBeNull();
    $reasons = app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking->fresh(), $session->fresh())['reasonCodes'];
    expect($reasons)->not->toContain('supervisor_arrival_unverified')
        ->and($reasons)->toContain('execution_path_not_enabled');
});

it('rejects supervisor arrival mutations from other supervisors and after terminal state', function (): void {
    [$supervisor, $booking, $session] = phase28TaskFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/supervisor";
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->postJson("$base/start-travel")->assertNotFound();
    expect($session->supervisorAttendances()->count())->toBe(0);

    Sanctum::actingAs($supervisor);
    $session->forceFill(['status' => 'completed'])->save();
    $this->postJson("$base/start-travel")->assertUnprocessable();
    expect($session->supervisorAttendances()->count())->toBe(0);
});

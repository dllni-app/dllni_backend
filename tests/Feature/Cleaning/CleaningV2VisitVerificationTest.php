<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV2VisitTaskAllocationService;
use Modules\Cleaning\Services\CleaningV2VisitExecutionCapabilityService;
use Modules\Cleaning\Services\CleaningV2VisitWorkerAttestationService;
use Spatie\Permission\Models\Role;

function g2cVerifiedFixture(bool $allocate = true, bool $arrivals = true): array
{
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time', 'number_of_workers' => 2,
        'status' => 'awaiting_start_verification', 'is_pricing_final' => true,
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'actual_time',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 3,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'fully_covered',
        'status' => 'awaiting_start_verification',
        'is_pricing_final' => true,
        'open_time_expected_max_minutes' => 180,
        'pricing_snapshot' => ['scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => 2, 'expectedMaxMinutes' => 180],
    ]);
    $owner = $session->supervisorAssignments()->create([
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(), 'compensation_hourly_rate' => 100,
        'active_slot' => 1,
    ]);
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $worker = Worker::factory()->create();
        $workers[] = $worker->id;
        $session->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => 'accepted_waiting_for_order_start',
            'accepted_at' => now(),
            'started_travel_at' => $arrivals ? now()->subMinutes(30) : null,
            'arrived_at' => $arrivals ? now()->subMinutes(10) : null,
        ]);
    }
    if ($allocate) {
        app(CleaningV2VisitTaskAllocationService::class)->allocate($supervisor, $booking, $session, [[
            'taskKey' => 'visit_scope', 'workerIds' => $workers,
        ]]);
    }
    if ($arrivals) {
        $session->supervisorAttendances()->create([
            'cleaning_booking_session_supervisor_assignment_id' => $owner->id,
            'started_travel_at' => now()->subMinutes(30),
            'arrived_at' => now()->subMinutes(5),
            'last_actor_user_id' => $supervisor->id,
        ]);
    }
    if ($allocate && $arrivals) {
        foreach ($workers as $workerId) {
            app(CleaningV2VisitWorkerAttestationService::class)->certify(
                $supervisor, $booking, $session, $workerId, 'present', null,
            );
        }
    }

    return [$supervisor, $booking, $session, $workers];
}

it('issues an encrypted customer-only V2 visit challenge, verifies once and never starts work', function (): void {
    [$supervisor, $booking, $session] = g2cVerifiedFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    $customer = "/api/v1/user/cleaning/v2/bookings/{$booking->id}/visits/1/verification-code";
    Sanctum::actingAs($supervisor);
    $this->postJson("$base/verification-requests")->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.executionEnabled', false)
        ->assertDontSee('code_hash')->assertDontSee('customer_code_ciphertext');
    $this->postJson("$base/verification-requests")->assertOk();
    $this->getJson("$base/verification/status")->assertJsonPath('data.status', 'pending');
    $record = $session->executionVerifications()->firstOrFail();
    expect($session->executionVerifications()->count())->toBe(1)
        ->and($record->attempts)->toBe(0)
        ->and($record->code_hash)->not->toBeNull()
        ->and($record->customer_code_ciphertext)->not->toBeNull();

    Sanctum::actingAs($booking->customer);
    $response = $this->getJson($customer)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.purpose', 'v2_visit_supervisor_start_verification');
    $code = $response->json('data.code');
    expect($code)->toMatch('/^[1-9][0-9]{5}$/')
        ->and(Crypt::decryptString($record->customer_code_ciphertext))->toBe($code);

    Sanctum::actingAs($supervisor);
    $this->postJson("$base/verification/confirm", ['code' => $code])->assertOk()
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.executionEnabled', false);
    $record = $record->fresh();
    expect($record->consumed_at)->not->toBeNull()
        ->and($record->customer_code_ciphertext)->toBeNull()
        ->and($record->code_hash)->toBeNull()
        ->and($session->fresh()->work_started_at)->toBeNull()
        ->and($booking->fresh()->work_started_at)->toBeNull();
    $readiness = app(CleaningV2VisitExecutionCapabilityService::class)->readiness($booking->fresh(), $session->fresh());
    expect($readiness['reasonCodes'])->not->toContain('customer_supervisor_verification_unproven')
        ->and($readiness['executionCapability'])->toBeFalse()
        ->and($readiness['reasonCodes'])->toContain('execution_path_not_enabled');
    $this->postJson("$base/verification/confirm", ['code' => $code])->assertUnprocessable();
    $this->postJson("$base/verification-requests")->assertJsonPath('data.status', 'verified');
    expect($session->executionVerifications()->count())->toBe(1);
    Sanctum::actingAs($booking->customer);
    $this->getJson($customer)->assertNotFound();
});

it('rejects wrong customers, nonowner supervisors and other booking visit routes', function (): void {
    [$supervisor, $booking, $session] = g2cVerifiedFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    $customer = "/api/v1/user/cleaning/v2/bookings/{$booking->id}/visits/1/verification-code";
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->postJson("$base/verification-requests")->assertNotFound();
    $this->postJson("$base/verification/confirm", ['code' => '123456'])->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->postJson("$base/verification-requests")->assertForbidden();
    $this->getJson($customer)->assertNotFound();
    Sanctum::actingAs($supervisor);
    $this->postJson("$base/verification-requests")->assertOk();
    $this->getJson("$base/verification/status")
        ->assertJsonPath('data.requestAvailable', true);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson($customer)->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->getJson("/api/v1/user/cleaning/v2/bookings/{$booking->id}/visits/77/verification-code")->assertNotFound();
});

it('enforces wrong-code attempts, expiration, rotation and consumed-code replay protection', function (): void {
    [$supervisor, $booking, $session] = g2cVerifiedFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    Sanctum::actingAs($supervisor);
    $this->postJson("$base/verification-requests")->assertOk();
    $record = $session->executionVerifications()->firstOrFail();
    $valid = Crypt::decryptString($record->customer_code_ciphertext);
    $wrong = $valid === '111111' ? '222222' : '111111';
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson("$base/verification/confirm", ['code' => $wrong])->assertUnprocessable();
    }
    $record = $record->fresh();
    expect($record->attempts)->toBe(5)
        ->and($record->status)->toBe('invalidated')
        ->and($record->customer_code_ciphertext)->toBeNull()
        ->and($record->active_slot)->toBeNull();
    $this->getJson("$base/verification/status")->assertJsonPath('data.status', 'not_requested');
    $this->postJson("$base/verification-requests")->assertOk();
    $active = $session->executionVerifications()->where('active_slot', 1)->firstOrFail();
    expect($active->id)->not->toBe($record->id)
        ->and($active->status)->toBe('pending');
    $active->forceFill(['expires_at' => now()->subSecond()])->save();
    $this->postJson("$base/verification-requests")->assertOk();
    expect($session->executionVerifications()->count())->toBe(3)
        ->and($active->fresh()->invalidated_at)->not->toBeNull()
        ->and($active->fresh()->code_hash)->toBeNull();
});

it('refuses challenge issuance without signed task membership, arrived team or configured compensation', function (): void {
    [$supervisor, $booking, $session, $workers] = g2cVerifiedFixture(false);
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/verification-requests";
    Sanctum::actingAs($supervisor);
    $this->postJson($url)->assertUnprocessable();
    expect($session->executionVerifications()->count())->toBe(0);
    app(CleaningV2VisitTaskAllocationService::class)->allocate($supervisor, $booking, $session, [[
        'taskKey' => 'visit_scope', 'workerIds' => $workers,
    ]]);
    $session->supervisorAssignments()->where('active_slot', 1)->update(['compensation_hourly_rate' => 0]);
    $this->postJson($url)->assertUnprocessable();
    $session->supervisorAssignments()->where('active_slot', 1)->update(['compensation_hourly_rate' => 100]);
    $session->workerAssignments()->where('worker_id', $workers[0])->update(['arrived_at' => null]);
    $this->postJson($url)->assertUnprocessable();
    $session->workerAssignments()->where('worker_id', $workers[0])->update(['arrived_at' => now()]);
    foreach ($workers as $workerId) {
        app(CleaningV2VisitWorkerAttestationService::class)->certify(
            $supervisor, $booking, $session, $workerId, 'present', null,
        );
    }
    $this->postJson($url)->assertOk();
    $session->workerAssignments()->where('worker_id', $workers[1])->update(['released_at' => now()]);
    $this->postJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/verification/confirm", ['code' => '111111'])
        ->assertUnprocessable();
    expect($session->executionVerifications()->where('active_slot', 1)->first()->attempts)->toBe(0);
    Sanctum::actingAs($booking->customer);
    $this->getJson("/api/v1/user/cleaning/v2/bookings/{$booking->id}/visits/1/verification-code")
        ->assertNotFound();
});

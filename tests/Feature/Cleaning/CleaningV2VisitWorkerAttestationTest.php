<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV2VisitTaskAllocationService;
use Spatie\Permission\Models\Role;

function g2cAttestationFixture(): array
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
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00', 'duration_hours' => 3,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(), 'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned', 'is_pricing_final' => true,
        'open_time_expected_max_minutes' => 180,
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => 2, 'expectedMaxMinutes' => 180,
        ],
    ]);
    $owner = $session->supervisorAssignments()->create([
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(), 'compensation_hourly_rate' => 100,
        'active_slot' => 1,
    ]);
    $workers = [];
    foreach (range(1, 2) as $_) {
        $worker = Worker::factory()->create();
        $workers[] = $worker->id;
        $session->workerAssignments()->create([
            'worker_id' => $worker->id, 'status' => 'accepted_waiting_for_order_start',
            'accepted_at' => now(), 'started_travel_at' => now()->subMinutes(30),
            'arrived_at' => now()->subMinutes(5),
        ]);
    }
    app(CleaningV2VisitTaskAllocationService::class)->allocate($supervisor, $booking, $session, [
        ['taskKey' => 'visit_scope', 'workerIds' => $workers],
    ]);
    $session->supervisorAttendances()->create([
        'cleaning_booking_session_supervisor_assignment_id' => $owner->id,
        'started_travel_at' => now()->subMinutes(35), 'arrived_at' => now()->subMinutes(5),
        'last_actor_user_id' => $supervisor->id,
    ]);

    return [$supervisor, $booking, $session, $workers];
}

it('certifies each worker independently with immutable supervisor provenance and no work start or payment', function (): void {
    [$supervisor, $booking, $session, $workers] = g2cAttestationFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/attendance-certifications";
    Sanctum::actingAs($supervisor);
    $this->getJson($base)->assertOk()->assertJsonPath('data.certificationAvailable', true)
        ->assertJsonPath('data.actualAttendanceCertified', false)
        ->assertJsonPath('data.certifiedWorkers', 0);
    $payload = ['workerId' => $workers[0], 'outcome' => 'present'];
    $this->postJson($base, $payload)->assertOk()
        ->assertJsonPath('data.certifiedWorkers', 1)
        ->assertJsonPath('data.actualAttendanceCertified', false)
        ->assertJsonPath('data.workExecutionEnabled', false)
        ->assertJsonPath('data.settlementEnabled', false);
    $before = $session->workerAttestations()->firstOrFail();
    $this->postJson($base, $payload)->assertOk();
    expect($session->workerAttestations()->count())->toBe(1)
        ->and($before->fresh()->certified_at->toIso8601String())->toBe($before->certified_at->toIso8601String());
    $this->postJson($base, ['workerId' => $workers[1], 'outcome' => 'present'])
        ->assertOk()->assertJsonPath('data.certifiedWorkers', 2)
        ->assertJsonPath('data.actualAttendanceCertified', true);
    expect($session->workerAttestations()->count())->toBe(2)
        ->and($session->workerAttestations()->first()->recorded_by_user_id)->toBe($supervisor->id)
        ->and($session->fresh()->work_started_at)->toBeNull()
        ->and($session->fresh()->payment_settled_at)->toBeNull()
        ->and($booking->fresh()->work_started_at)->toBeNull();
    $this->postJson($base, ['workerId' => $workers[0], 'outcome' => 'no_show', 'note' => 'Absent despite arrival flag'])
        ->assertUnprocessable();
});

it('restricts no-show to visit start with a reason and blocks customer verification until resolved', function (): void {
    [$supervisor, $booking, $session, $workers] = g2cAttestationFixture();
    Sanctum::actingAs($supervisor);
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    $this->postJson("$base/attendance-certifications", [
        'workerId' => $workers[0], 'outcome' => 'no_show', 'note' => 'Was not present at the site',
    ])->assertUnprocessable();
    Carbon::setTestNow(Carbon::parse($session->scheduled_date->toDateString().' 10:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus')));
    try {
        $this->postJson("$base/attendance-certifications", [
            'workerId' => $workers[0], 'outcome' => 'no_show', 'note' => 'short',
        ])->assertUnprocessable();
        $this->postJson("$base/attendance-certifications", [
            'workerId' => $workers[0], 'outcome' => 'no_show', 'note' => 'Worker absent at scheduled start',
        ])->assertOk()->assertJsonPath('data.noShowWorkers', 1)
            ->assertJsonPath('data.actualAttendanceCertified', false);
        $this->postJson("$base/attendance-certifications", [
            'workerId' => $workers[1], 'outcome' => 'present',
        ])->assertOk();
        $this->postJson("$base/verification-requests")->assertUnprocessable();
        expect($session->executionVerifications()->count())->toBe(0)
            ->and($session->workerAssignments()->whereNull('released_at')->count())->toBe(2)
            ->and($session->workerAttestations()->where('outcome', 'no_show')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('denies other supervisors customers outsiders and invented workers and rejects missing arrival', function (): void {
    [$supervisor, $booking, $session, $workers] = g2cAttestationFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/attendance-certifications";
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->getJson($base)->assertNotFound();
    $this->postJson($base, ['workerId' => $workers[0], 'outcome' => 'present'])->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->postJson($base, ['workerId' => $workers[0], 'outcome' => 'present'])->assertForbidden();
    Sanctum::actingAs($supervisor);
    $this->postJson($base, ['workerId' => 999999, 'outcome' => 'present'])->assertUnprocessable();
    $session->workerAssignments()->where('worker_id', $workers[0])->update(['arrived_at' => null]);
    $this->postJson($base, ['workerId' => $workers[0], 'outcome' => 'present'])->assertUnprocessable();
    expect($session->workerAttestations()->count())->toBe(0);
});

it('fails closed for released membership terminal visit or missing supervisor compensation', function (): void {
    [$supervisor, $booking, $session, $workers] = g2cAttestationFixture();
    Sanctum::actingAs($supervisor);
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/attendance-certifications";
    $session->workerAssignments()->where('worker_id', $workers[0])->update(['released_at' => now()]);
    $this->getJson($base)->assertJsonPath('data.certificationAvailable', false);
    $this->postJson($base, ['workerId' => $workers[1], 'outcome' => 'present'])->assertUnprocessable();
    $session->workerAssignments()->where('worker_id', $workers[0])->update(['released_at' => null]);
    $session->supervisorAssignments()->where('active_slot', 1)->update(['compensation_hourly_rate' => 0]);
    $this->postJson($base, ['workerId' => $workers[1], 'outcome' => 'present'])->assertUnprocessable();
    $session->supervisorAssignments()->where('active_slot', 1)->update(['compensation_hourly_rate' => 100]);
    $session->forceFill(['status' => 'completed'])->save();
    $this->postJson($base, ['workerId' => $workers[1], 'outcome' => 'present'])->assertUnprocessable();
    expect($session->workerAttestations()->count())->toBe(0);
});

it('reports only evidenced liabilities and a conservative unfunded minimum without approving settlement', function (): void {
    [$supervisor, $booking, $session, $workers] = g2cAttestationFixture();
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/funding-preflight";
    Sanctum::actingAs($supervisor);
    $this->getJson($url)->assertOk()->assertJsonPath('data.fundingProven', false)
        ->assertJsonPath('data.startWorkAllowed', false)
        ->assertJsonPath('data.settlementAllowed', false)
        ->assertJsonPath('data.workerEntitlementsTotal', null);

    $booking->forceFill(['total_price' => 1000, 'is_pricing_final' => true])->save();
    $session->forceFill(['total_price' => 1000, 'is_pricing_final' => true])->save();
    foreach ($workers as $workerId) {
        $session->workerAssignments()->where('worker_id', $workerId)
            ->update(['worker_amount' => 450, 'service_share_amount' => 440, 'travel_fee' => 10]);
    }
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.visitCustomerQuote', 1000)
        ->assertJsonPath('data.workerEntitlementsTotal', 900)
        ->assertJsonPath('data.workerTravelIncludedTotal', 20)
        ->assertJsonPath('data.supervisorProjectedShare', 300)
        ->assertJsonPath('data.knownObligationsMinimum', 1200)
        ->assertJsonPath('data.unfundedMinimum', 200)
        ->assertJsonPath('data.fundingProven', false)
        ->assertJsonPath('data.startWorkAllowed', false);
    $session->forceFill(['total_price' => 1500])->save();
    $this->getJson($url)->assertJsonPath('data.unfundedMinimum', 0)
        ->assertJsonPath('data.fundingProven', false)
        ->assertJsonPath('data.startWorkAllowed', false);
    expect($session->fresh()->work_started_at)->toBeNull()
        ->and($session->fresh()->payment_settled_at)->toBeNull();
});

it('hides supervisor financial preflight from customers and unrelated supervisors', function (): void {
    [$supervisor, $booking] = g2cAttestationFixture();
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/funding-preflight";
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->getJson($url)->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->getJson($url)->assertForbidden();
    Sanctum::actingAs($supervisor);
    $this->getJson($url)->assertOk();
});

it('combines task verification attendance and funding blockers into private read-only execution readiness', function (): void {
    [$supervisor, $booking, $session] = g2cAttestationFixture();
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1/execution-readiness";
    Sanctum::actingAs($supervisor);
    $this->getJson($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.executionCapability', false)
        ->assertJsonPath('data.fundingProven', false)
        ->assertJsonPath('data.settlementAllowed', false)
        ->assertJsonPath('data.activeSupervisorWorkSegments', 0)
        ->assertJsonPath('data.activeWorkerWorkSegments', 0)
        ->assertJsonPath('data.taskCount', 1)
        ->assertJsonPath('data.allowedActions', []);
    $reasons = $this->getJson($url)->assertOk()->json('data.reasonCodes');
    expect($reasons)->toContain('customer_supervisor_verification_unproven')
        ->toContain('actual_attendance_not_certified')
        ->toContain('approved_funding_source_unproven')
        ->toContain('execution_path_not_enabled');
    expect($session->fresh()->work_started_at)->toBeNull()
        ->and($session->fresh()->payment_settled_at)->toBeNull();
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
    Sanctum::actingAs($other);
    $this->getJson($url)->assertNotFound();
    Sanctum::actingAs($booking->customer);
    $this->getJson($url)->assertForbidden();
});

it('does not allow administrative direct completion of a provisional V2 group visit', function (): void {
    [$supervisor, $booking, $session] = g2cAttestationFixture();
    $session->forceFill([
        'status' => 'awaiting_customer_completion',
        'is_pricing_final' => false,
    ])->save();
    expect(fn () => app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class)
        ->confirmCompletion($booking, $session, (int) $booking->customer_id, true))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect($session->fresh()->payment_status)->not->toBe('settled')
        ->and($session->fresh()->payment_settled_at)->toBeNull()
        ->and($session->fresh()->work_started_at)->toBeNull()
        ->and($booking->fresh()->status?->value ?? $booking->fresh()->status)->not->toBe('completed');
});

it('records exactly one pending replacement request for an immutable certified no-show without releasing the worker', function (): void {
    [$supervisor, $booking, $session, $workerIds] = g2cAttestationFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    Sanctum::actingAs($supervisor);

    $this->getJson("$base/replacement-requests")->assertOk()
        ->assertJsonPath('data.pendingRequests', 0);
    $this->postJson("$base/replacement-requests", [
        'workerId' => $workerIds[0], 'reason' => 'Reported absence at the site',
    ])->assertUnprocessable();

    Carbon::setTestNow(Carbon::parse($session->scheduled_date->toDateString().' 10:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus')));
    try {
        $this->postJson("$base/attendance-certifications", [
            'workerId' => $workerIds[0], 'outcome' => 'no_show',
            'note' => 'Worker did not arrive at scheduled visit',
        ])->assertOk();

        $request = ['workerId' => $workerIds[0], 'reason' => 'Replacement required for documented no-show'];
        $this->postJson("$base/replacement-requests", $request)->assertOk()
            ->assertJsonPath('data.pendingRequests', 1)
            ->assertJsonPath('data.requests.0.workerId', $workerIds[0])
            ->assertJsonPath('data.requests.0.status', 'pending')
            ->assertJsonPath('data.replacementAssigned', false)
            ->assertJsonPath('data.workExecutionEnabled', false)
            ->assertJsonPath('data.settlementEnabled', false);

        $original = $session->replacementRequests()->firstOrFail();
        $this->postJson("$base/replacement-requests", $request)->assertOk()
            ->assertJsonPath('data.pendingRequests', 1);
        $this->postJson("$base/replacement-requests", [
            'workerId' => $workerIds[0], 'reason' => 'Changed reason after original request',
        ])->assertUnprocessable();

        expect($session->replacementRequests()->count())->toBe(1)
            ->and($original->fresh()->reason)->toBe($request['reason'])
            ->and($session->workerAssignments()->whereNull('released_at')->count())->toBe(2)
            ->and($session->workerAttestations()->where('outcome', 'no_show')->count())->toBe(1)
            ->and($session->fresh()->work_started_at)->toBeNull()
            ->and($session->fresh()->payment_settled_at)->toBeNull();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole(Role::findOrCreate('Cleaning Supervisor', 'web'));
        Sanctum::actingAs($other);
        $this->getJson("$base/replacement-requests")->assertNotFound();
        $this->postJson("$base/replacement-requests", $request)->assertNotFound();
        Sanctum::actingAs($booking->customer);
        $this->getJson("$base/replacement-requests")->assertForbidden();
        $this->postJson("$base/replacement-requests", $request)->assertForbidden();
    } finally {
        Carbon::setTestNow();
    }
});

it('fails closed for a replacement request after the customer verification is consumed', function (): void {
    [$supervisor, $booking, $session, $workerIds] = g2cAttestationFixture();
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    $session->executionVerifications()->create([
        'cleaning_booking_session_supervisor_assignment_id' => $session->activeSupervisorAssignment->id,
        'customer_user_id' => $booking->customer_id,
        'status' => 'verified', 'expires_at' => now()->addMinutes(10),
        'verified_at' => now(), 'consumed_at' => now(), 'active_slot' => 1,
    ]);
    Sanctum::actingAs($supervisor);
    $this->postJson("$base/replacement-requests", [
        'workerId' => $workerIds[0], 'reason' => 'Attempted replacement after start verification',
    ])->assertUnprocessable();
    expect($session->replacementRequests()->count())->toBe(0);
});

it('requires independent approval and replacement opt in before reallocating an immutable no show', function (): void {
    [$supervisor, $booking, $session, $workerIds] = g2cAttestationFixture();
    $service = app(Modules\Cleaning\Services\CleaningV2VisitReplacementRequestService::class);
    $replacementWorker = Worker::factory()->create([
        'is_active' => true, 'is_suspended' => false, 'security_deposit_status' => 'active',
    ]);
    $approver = User::factory()->create(['is_active' => true]);
    $approver->assignRole(Role::findOrCreate('admin', 'web'));
    Carbon::setTestNow(Carbon::parse($session->scheduled_date->toDateString().' 10:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus')));
    try {
        app(Modules\Cleaning\Services\CleaningV2VisitWorkerAttestationService::class)
            ->certify($supervisor, $booking, $session, $workerIds[0], 'no_show', 'Worker absent at appointed site');
        $service->request($supervisor, $booking, $session, $workerIds[0], 'Supervisor requested a documented replacement');
        $request = $session->replacementRequests()->firstOrFail();
        expect(fn () => $service->nominate($supervisor, $booking, $session, $request->id, $replacementWorker->id))
            ->toThrow(Illuminate\Validation\ValidationException::class);
        $service->approve($approver, $booking, $session, $request->id);
        $service->nominate($supervisor, $booking, $session, $request->id, $replacementWorker->id);
        expect(fn () => $service->reallocate($supervisor, $booking, $session, $request->id))
            ->toThrow(Illuminate\Validation\ValidationException::class);
        $service->accept($replacementWorker->user, $booking, $session, $request->id);
        $service->reallocate($supervisor, $booking, $session, $request->id);

        $replacementAssignment = $session->workerAssignments()->where('worker_id', $replacementWorker->id)->firstOrFail();
        $replacementAssignment->update(['started_travel_at' => now()->subMinutes(20), 'arrived_at' => now()]);
        $attestations = app(Modules\Cleaning\Services\CleaningV2VisitWorkerAttestationService::class);
        $attestations->certify($supervisor, $booking, $session, $workerIds[1], 'present', null);
        $attestations->certify($supervisor, $booking, $session, $replacementWorker->id, 'present', null);

        expect($request->fresh()->status)->toBe('reallocated')
            ->and($request->fresh()->approved_by_user_id)->toBe($approver->id)
            ->and($request->fresh()->accepted_by_user_id)->toBe($replacementWorker->user_id)
            ->and($session->workerAttestations()->where('outcome', 'no_show')->count())->toBe(1)
            ->and($session->workerAssignments()->whereNull('released_at')->count())->toBe(2)
            ->and($session->executionTasks()->firstOrFail()->workers()->where('active_slot', 1)->count())->toBe(2)
            ->and($session->executionTasks()->firstOrFail()->workers()->whereNull('active_slot')->count())->toBe(1)
            ->and($attestations->allPresent($session, $session->activeSupervisorAssignment->id))->toBeTrue()
            ->and($session->fresh()->payment_settled_at)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('routes the replacement approval nominee and worker opt-in through real authenticated API roles', function (): void {
    [$supervisor, $booking, $session, $workerIds] = g2cAttestationFixture();
    $replacementWorker = Worker::factory()->create([
        'is_active' => true, 'is_suspended' => false, 'security_deposit_status' => 'active',
    ]);
    $approver = User::factory()->create(['is_active' => true]);
    $approver->assignRole(Role::findOrCreate('admin', 'web'));
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";
    Carbon::setTestNow(Carbon::parse($session->scheduled_date->toDateString().' 10:00',
        config('cleaning_booking_v2.timezone', 'Asia/Damascus')));

    try {
        Sanctum::actingAs($supervisor);
        $this->postJson("$base/attendance-certifications", [
            'workerId' => $workerIds[0], 'outcome' => 'no_show',
            'note' => 'Certified absent by supervisor at site',
        ])->assertOk();
        $this->postJson("$base/replacement-requests", [
            'workerId' => $workerIds[0], 'reason' => 'Supervisor requested documented no-show replacement',
        ])->assertOk()->assertJsonPath('data.pendingRequests', 1);
        $requestId = $session->replacementRequests()->firstOrFail()->id;
        $adminUrl = "/api/v1/admin/cleaning/v2/bookings/{$booking->id}/visits/1/replacement-requests/{$requestId}/approve";
        $workerUrl = "/api/v1/cleaning/v2/bookings/{$booking->id}/visits/1/replacement-requests/{$requestId}/accept";

        $this->postJson($adminUrl)->assertForbidden();
        Sanctum::actingAs($approver);
        $this->postJson($adminUrl)->assertOk()->assertJsonPath('data.requests.0.status', 'approved');
        Sanctum::actingAs($supervisor);
        $this->postJson("$base/replacement-requests/$requestId/nominate", [
            'workerId' => $replacementWorker->id,
        ])->assertOk()->assertJsonPath('data.requests.0.status', 'offered');
        $this->postJson("$base/replacement-requests/$requestId/reallocate")->assertUnprocessable();

        Sanctum::actingAs($booking->customer);
        $this->getJson('/api/v1/cleaning/v2/replacement-offers')->assertForbidden();
        $this->postJson($workerUrl)->assertUnprocessable();
        Sanctum::actingAs(Worker::factory()->create()->user);
        $this->getJson('/api/v1/cleaning/v2/replacement-offers')
            ->assertOk()->assertJsonPath('data.offers', []);
        Sanctum::actingAs($replacementWorker->user);
        $this->getJson('/api/v1/cleaning/v2/replacement-offers')->assertOk()
            ->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.bookingId', $booking->id)
            ->assertJsonPath('data.offers.0.visitSequence', 1)
            ->assertJsonPath('data.offers.0.requestId', $requestId);
        $this->postJson($workerUrl)->assertOk()->assertJsonPath('data.requests.0.status', 'accepted');
        $this->getJson('/api/v1/cleaning/v2/replacement-offers')
            ->assertOk()->assertJsonPath('data.offers', []);

        Sanctum::actingAs($supervisor);
        $this->postJson("$base/replacement-requests/$requestId/reallocate")
            ->assertOk()->assertJsonPath('data.requests.0.status', 'reallocated')
            ->assertJsonPath('data.replacementAssigned', true)
            ->assertJsonPath('data.settlementEnabled', false);

        expect($session->workerAssignments()->whereNull('released_at')->count())->toBe(2)
            ->and($session->workerAssignments()->where('worker_id', $workerIds[0])->firstOrFail()->released_at)->not->toBeNull()
            ->and($session->workerAttestations()->where('outcome', 'no_show')->count())->toBe(1)
            ->and($session->supervisorWorkSegments()->count())->toBe(0)
            ->and($session->fresh()->payment_settled_at)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

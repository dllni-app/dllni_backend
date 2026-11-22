<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV3WorkClockCommandService;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @return array{0:CleaningBookingSession,1:User,2:list<User>} */
function v3CommandsFixture(int $workers = 1, string $supervisionMode = 'worker'): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'booking_kind' => 'standard',
        'number_of_workers' => $workers,
        'total_price' => 3900,
    ]);
    $booking->forceFill(['contract_version' => 'v3'])->save();

    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->toDateString(),
        'scheduled_time' => now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => $workers,
        'supervision_mode' => $supervisionMode,
        'coverage_status' => 'fully_covered',
        'status' => 'in_progress',
        'work_started_at' => now(),
    ]);

    $users = [];
    for ($index = 0; $index < $workers; $index++) {
        $workerUser = User::factory()->create(['is_active' => true]);
        $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
        $visit->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => 'in_progress',
            'accepted_at' => now()->subMinutes($workers - $index),
            'work_started_at' => now(),
        ]);
        $users[] = $workerUser;
    }

    $started = app(CleaningV3WorkClockCommandService::class)
        ->initializeApprovedVisit((int) $booking->id, (int) $visit->id);
    expect($started['state'])->toBe('running')
        ->and($started['revision'])->toBe(1);

    return [$visit, $customer, $users];
}

function v3CommandsUrl(CleaningBookingSession $visit): string
{
    return "/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/{$visit->id}";
}

test('the customer requests and withdraws a pause without stopping the server clock', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);

    Sanctum::actingAs($customer);
    $this->travel(2)->minutes();

    $request = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-abc', 'expectedRevision' => 1,
    ])->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.pausePending', true)
        ->assertJsonPath('replayed', false);
    $id = $request->json('pauseRequestId');

    $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-abc', 'expectedRevision' => 1,
    ])->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('pauseRequestId', $id);

    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.pendingPauseRequestId', $id)
        ->assertJsonPath('data.activeSeconds', 120);

    $this->postJson($url.'/pause-requests/'.$id.'/withdraw', [
        'commandKey' => 'withdraw-abc', 'expectedRevision' => 2,
    ])->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.pausePending', false)
        ->assertJsonPath('data.revision', 3);

    $this->postJson($url.'/pause-requests/'.$id.'/withdraw', [
        'commandKey' => 'withdraw-abc', 'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('replayed', true);

    expect(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(1)
        ->and(DB::table('cleaning_work_pause_requests')->where('id', $id)->value('status'))->toBe('withdrawn');
});

test('current leader approves a pause and resumes without counting paused seconds', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture(2);
    $url = v3CommandsUrl($visit);

    Sanctum::actingAs($customer);
    $this->travel(2)->minutes();
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-first', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    Sanctum::actingAs($workers[1]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'wrong-leader', 'expectedRevision' => 2,
    ])->assertNotFound();

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'approve-first', 'expectedRevision' => 2,
    ])->assertOk()
        ->assertJsonPath('data.state', 'paused')
        ->assertJsonPath('data.activeSeconds', 120)
        ->assertJsonPath('data.revision', 3)
        ->assertJsonPath('data.activeSince', null);

    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'approve-first', 'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('replayed', true);

    $this->travel(20)->minutes();
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.activeSeconds', 120);

    $this->postJson($url.'/resume', [
        'commandKey' => 'resume-first', 'expectedRevision' => 3,
    ])->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.revision', 4)
        ->assertJsonPath('data.activeSeconds', 120)
        ->assertJsonPath('replayed', false);

    $this->postJson($url.'/resume', [
        'commandKey' => 'resume-first', 'expectedRevision' => 3,
    ])->assertOk()->assertJsonPath('replayed', true);

    $this->travel(3)->minutes();
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.activeSeconds', 300);

    expect(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->count())->toBe(2)
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(1)
        ->and((float) $visit->booking->fresh()->getRawOriginal('total_price'))->toBe(3900.0);
});

test('an authorized rejection does not stop work or create a new work interval', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);

    $this->travel(1)->minutes();
    Sanctum::actingAs($customer);
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-reject', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'reject', 'commandKey' => 'reject-pausing', 'expectedRevision' => 2,
    ])->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.revision', 3)
        ->assertJsonPath('data.pausePending', false);

    expect(DB::table('cleaning_work_pause_requests')->where('id', $id)->value('status'))->toBe('rejected')
        ->and(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->count())->toBe(1);
});

test('stale revisions, pending duplicates and re-used command keys are rejected without mutation', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);

    Sanctum::actingAs($customer);
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-for-conflict', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-other', 'expectedRevision' => 1,
    ])->assertStatus(409)->assertJsonPath('code', 'STALE_CLOCK_REVISION');

    $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-other', 'expectedRevision' => 2,
    ])->assertStatus(409)->assertJsonPath('code', 'CLOCK_NOT_RUNNING_OR_PAUSE_PENDING');

    $this->postJson($url.'/pause-requests/'.$id.'/withdraw', [
        'commandKey' => 'pause-for-conflict', 'expectedRevision' => 2,
    ])->assertStatus(409)->assertJsonPath('code', 'CLOCK_COMMAND_KEY_CONFLICT');

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'bad-revision', 'expectedRevision' => 1,
    ])->assertStatus(409)->assertJsonPath('code', 'STALE_CLOCK_REVISION');

    expect(DB::table('cleaning_work_pause_requests')->where('id', $id)->value('status'))->toBe('pending')
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(1);
});

test('a replaced team leader loses approval authority and the next active worker becomes leader', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture(2);
    $url = v3CommandsUrl($visit);

    Sanctum::actingAs($customer);
    $this->travel(1)->minutes();
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-change-lead', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    $firstWorkerId = $workers[0]->worker->id;
    DB::table('cleaning_booking_session_worker_assignments')
        ->where('cleaning_booking_session_id', $visit->id)
        ->where('worker_id', $firstWorkerId)
        ->update(['released_at' => now(), 'status' => 'withdrawn']);

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'old-leader', 'expectedRevision' => 2,
    ])->assertNotFound();

    Sanctum::actingAs($workers[1]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'new-leader', 'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('data.state', 'paused');

    $this->postJson($url.'/resume', [
        'commandKey' => 'resume-short-team', 'expectedRevision' => 3,
    ])->assertStatus(409)->assertJsonPath('code', 'V3_CREW_INCOMPLETE');
});

test('a supervised visit can only be paused by the currently assigned supervisor', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture(2, 'supervisor');
    $url = v3CommandsUrl($visit);
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');

    Sanctum::actingAs($customer);
    $this->travel(1)->minutes();
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-supervised', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'worker-not-supervisor', 'expectedRevision' => 2,
    ])->assertNotFound();

    Sanctum::actingAs($supervisor);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'unassigned-supervisor', 'expectedRevision' => 2,
    ])->assertNotFound();

    $assignmentId = DB::table('cleaning_supervisor_assignments')->insertGetId([
        'cleaning_booking_id' => $visit->cleaning_booking_id,
        'supervisor_user_id' => $supervisor->id,
        'active_slot' => 1,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'real-supervisor', 'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('data.state', 'paused');

    DB::table('cleaning_supervisor_assignments')->where('id', $assignmentId)->update([
        'active_slot' => null, 'released_at' => now(),
    ]);
    $this->postJson($url.'/resume', [
        'commandKey' => 'revoked-supervisor', 'expectedRevision' => 3,
    ])->assertNotFound();
});

test('resuming a paused V3 visit refuses conflicts with another accepted worker booking', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);
    $this->travel(1)->minutes();

    Sanctum::actingAs($customer);
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-conflict', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'approve-conflict', 'expectedRevision' => 2,
    ])->assertOk();

    $other = CleaningBooking::factory()->create(['status' => 'worker_assigned']);
    $otherSession = $other->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->toDateString(),
        'scheduled_time' => now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned',
    ]);
    $otherSession->workerAssignments()->create([
        'worker_id' => $workers[0]->worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    $this->postJson($url.'/resume', [
        'commandKey' => 'resume-with-conflict', 'expectedRevision' => 3,
    ])->assertStatus(409)->assertJsonPath('code', 'V3_VISIT_RESCHEDULE_REQUIRED');

    expect(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->count())->toBe(1)
        ->and($visit->fresh()->clock_revision)->toBe(3);
});

test('V2 visits, unrelated customers and wrong session identities cannot mutate V3 clocks', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);
    $outsider = User::factory()->create(['is_active' => true]);

    Sanctum::actingAs($outsider);
    $this->postJson($url.'/pause-requests', [
        'commandKey' => 'outsider', 'expectedRevision' => 1,
    ])->assertNotFound();

    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/9999999/pause-requests", [
        'commandKey' => 'wrong-session', 'expectedRevision' => 1,
    ])->assertNotFound();

    $legacy = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $legacyVisit = $legacy->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $this->postJson(v3CommandsUrl($legacyVisit).'/pause-requests', [
        'commandKey' => 'legacy-booking', 'expectedRevision' => 0,
    ])->assertNotFound();

    $this->postJson('/api/v1/cleaning/v3/quotes', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
});

test('verified V3 worker-start lifecycle opens the first clock interval once, without a client timer command', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'worker_assigned',
        'booking_kind' => 'standard',
    ]);
    $booking->forceFill(['contract_version' => 'v3'])->save();
    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->toDateString(),
        'scheduled_time' => now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => 'awaiting_worker_start_confirmation',
    ]);
    $visit->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'start_approved',
        'accepted_at' => now()->subMinutes(3),
        'arrived_at' => now()->subMinute(),
        'start_approved_at' => now(),
    ]);

    $lifecycle = app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class);
    $lifecycle->startWork($booking, $visit, $worker);

    expect($visit->fresh()->status->value)->toBe('in_progress')
        ->and($visit->fresh()->clock_revision)->toBe(1)
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(1);

    $lifecycle->startWork($booking->fresh(), $visit->fresh(), $worker);
    expect(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->count())->toBe(1)
        ->and($visit->fresh()->clock_revision)->toBe(1);

    $this->travel(1)->minutes();
    Sanctum::actingAs($customer);
    $this->getJson(v3CommandsUrl($visit).'/work-clock')->assertOk()
        ->assertJsonPath('data.activeSeconds', 60)
        ->assertJsonPath('data.state', 'running');
});

test('the final worker completion closes the V3 work interval and invalidates an undecided pause', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);
    $this->travel(5)->minutes();

    Sanctum::actingAs($customer);
    $pauseId = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-before-finish', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class)
        ->requestCompletion($visit->booking, $visit, $workers[0]->worker);

    expect($visit->fresh()->status->value)->toBe('awaiting_customer_completion')
        ->and(DB::table('cleaning_work_pause_requests')->where('id', $pauseId)->value('status'))->toBe('invalidated')
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(0);

    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.state', 'not_running')
        ->assertJsonPath('data.activeSeconds', 300)
        ->assertJsonPath('data.pausePending', false)
        ->assertJsonPath('data.revision', 3);

    $this->travel(10)->minutes();
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.activeSeconds', 300);

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$pauseId.'/decision', [
        'decision' => 'approve', 'commandKey' => 'late-approval', 'expectedRevision' => 3,
    ])->assertStatus(409)->assertJsonPath('code', 'PAUSE_REQUEST_NOT_PENDING');
});

test('a visit may be handed over during an approved pause without accruing more work time', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);
    $this->travel(1)->minutes();
    Sanctum::actingAs($customer);
    $id = $this->postJson($url.'/pause-requests', [
        'commandKey' => 'pause-before-handover', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');

    Sanctum::actingAs($workers[0]);
    $this->postJson($url.'/pause-requests/'.$id.'/decision', [
        'decision' => 'approve', 'commandKey' => 'approve-handover-pause', 'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('data.state', 'paused');

    app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class)
        ->requestCompletion($visit->booking, $visit, $workers[0]->worker);
    $this->travel(10)->minutes();
    Sanctum::actingAs($customer);
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.state', 'not_running')
        ->assertJsonPath('data.activeSeconds', 60)
        ->assertJsonPath('data.pausePending', false);
});

test('customer rejection of completion reopens the same V3 visit clock without resetting work history', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $url = v3CommandsUrl($visit);
    $this->travel(5)->minutes();

    $lifecycle = app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class);
    $lifecycle->requestCompletion($visit->booking, $visit, $workers[0]->worker);

    $lifecycle->rejectCompletion($visit->booking->fresh(), $visit->fresh(), $customer->id);

    expect($visit->fresh()->status->value)->toBe('in_progress')
        ->and(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->count())->toBe(2)
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(1);

    $this->travel(2)->minutes();
    Sanctum::actingAs($customer);
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.activeSeconds', 420)
        ->assertJsonPath('data.revision', 3);
});

test('a partially finished team keeps counting work until the final accepted worker finishes', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture(2);
    $url = v3CommandsUrl($visit);
    $this->travel(3)->minutes();

    $lifecycle = app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class);
    $lifecycle->requestCompletion($visit->booking, $visit, $workers[0]->worker);

    Sanctum::actingAs($customer);
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.activeSeconds', 180);
    $this->travel(2)->minutes();

    $lifecycle->requestCompletion($visit->booking->fresh(), $visit->fresh(), $workers[1]->worker);
    $this->getJson($url.'/work-clock')->assertOk()
        ->assertJsonPath('data.state', 'not_running')
        ->assertJsonPath('data.activeSeconds', 300);

    expect($visit->fresh()->status->value)->toBe('awaiting_customer_completion')
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(0);
});

test('closed V3 sessions cannot accept a fresh clock mutation even when a clock revision matches', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer] = v3CommandsFixture();
    $visit->forceFill(['status' => 'completed'])->save();
    Sanctum::actingAs($customer);

    $this->postJson(v3CommandsUrl($visit).'/pause-requests', [
        'commandKey' => 'after-completion', 'expectedRevision' => 1,
    ])->assertStatus(409)->assertJsonPath('code', 'V3_VISIT_TERMINAL');
});

test('V3 completion cannot settle through legacy V2 quote math even if a local visit is marked pricing-final', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    [$visit, $customer, $workers] = v3CommandsFixture();
    $visit->forceFill([
        'is_pricing_final' => true,
        'total_price' => 3900,
        'admin_margin_amount' => 0,
        'payment_status' => 'pending',
    ])->save();

    $this->travel(2)->minutes();
    $lifecycle = app(Modules\Cleaning\Services\CleaningBookingSessionLifecycleService::class);
    $lifecycle->requestCompletion($visit->booking, $visit, $workers[0]->worker);
    expect($visit->fresh()->payment_status)->toBe('pending');
    $completed = $lifecycle->confirmCompletion($visit->booking->fresh(), $visit->fresh(), $customer->id);

    expect($completed->status->value)->toBe('completed')
        ->and($completed->payment_status)->toBe('pending')
        ->and($completed->payment_settled_at)->toBeNull()
        ->and($completed->fresh()->clock_revision)->toBe(2)
        ->and((float) $completed->fresh()->getRawOriginal('total_price'))->toBe(3900.0)
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $visit->id)->count())->toBe(0);
});

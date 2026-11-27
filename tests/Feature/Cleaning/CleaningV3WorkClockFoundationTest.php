<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV3WorkClockProjectionService;

uses(RefreshDatabase::class);

function v3ClockTestVisit(?User $customer = null): CleaningBookingSession
{
    $customer ??= User::factory()->create();
    $booking = CleaningBooking::factory()->create(['customer_id' => $customer->id, 'total_price' => 3300]);
    $booking->forceFill(['contract_version' => 'v3'])->save();

    return $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => 'scheduled',
    ]);
}

function v3ClockInterval(CleaningBookingSession $visit, string $key, string $start, ?string $end = null): array
{
    return [
        'cleaning_booking_session_id' => $visit->id,
        'active_session_id' => $end === null ? $visit->id : null,
        'start_command_key' => $key,
        'end_command_key' => $end === null ? null : 'end-'.$key,
        'opened_clock_revision' => 1,
        'closed_clock_revision' => $end === null ? null : 2,
        'started_at' => $start,
        'ended_at' => $end,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('clock tables are additive and protect one open interval per V3 visit', function (): void {
    expect(Schema::hasColumns('cleaning_work_intervals', [
        'cleaning_booking_session_id', 'active_session_id', 'start_command_key',
        'end_command_key', 'opened_clock_revision', 'closed_clock_revision',
        'started_at', 'ended_at',
    ]))->toBeTrue();

    $uniqueIndexes = collect(Schema::getIndexes('cleaning_work_intervals'))
        ->filter(static fn (array $index): bool => $index['unique'])
        ->map(static fn (array $index): array => $index['columns']);

    expect($uniqueIndexes)->toContain(['active_session_id'])
        ->toContain(['cleaning_booking_session_id', 'start_command_key']);

    $visit = v3ClockTestVisit();
    $start = '2026-11-23 10:00:00';
    $first = v3ClockInterval($visit, 'start-1', $start);
    $second = v3ClockInterval($visit, 'start-2', '2026-11-23 12:00:00');
    DB::table('cleaning_work_intervals')->insert($first);

    expect(fn () => DB::table('cleaning_work_intervals')->insert($second))
        ->toThrow(QueryException::class);

    DB::table('cleaning_work_intervals')->where('start_command_key', 'start-1')->update([
        'active_session_id' => null,
        'end_command_key' => 'end-1',
        'ended_at' => '2026-11-23 11:00:00',
        'closed_clock_revision' => 2,
    ]);
    DB::table('cleaning_work_intervals')->insert($second);

    expect(DB::table('cleaning_work_intervals')->count())->toBe(2)
        ->and((float) $visit->booking->fresh()->getRawOriginal('total_price'))->toBe(3300.0);
});

test('pause requests prevent two pending requests and preserve completed history', function (): void {
    expect(Schema::hasColumns('cleaning_work_pause_requests', [
        'pending_session_id', 'command_key', 'requested_by',
        'expected_clock_revision', 'status', 'requested_at',
        'decided_by', 'decided_at', 'decision_command_key',
    ]))->toBeTrue();

    $visit = v3ClockTestVisit();
    $base = [
        'cleaning_booking_session_id' => $visit->id,
        'pending_session_id' => $visit->id,
        'requested_by' => $visit->booking->customer_id,
        'expected_clock_revision' => 1,
        'status' => 'pending',
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('cleaning_work_pause_requests')->insert([...$base, 'command_key' => 'pause-1']);
    expect(fn () => DB::table('cleaning_work_pause_requests')
        ->insert([...$base, 'command_key' => 'pause-2']))
        ->toThrow(QueryException::class);

    DB::table('cleaning_work_pause_requests')->where('command_key', 'pause-1')->update([
        'pending_session_id' => null,
        'status' => 'withdrawn',
        'decided_at' => now(),
    ]);
    DB::table('cleaning_work_pause_requests')->insert([...$base, 'command_key' => 'pause-2']);

    expect(DB::table('cleaning_work_pause_requests')->count())->toBe(2)
        ->and(DB::table('cleaning_work_pause_requests')->whereNotNull('pending_session_id')->count())->toBe(1);
});

test('work clock counts only active intervals and a pending pause does not stop the clock', function (): void {
    $visit = v3ClockTestVisit();
    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-1', '2026-11-23 10:00:00', '2026-11-23 11:00:00'),
    );
    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-2', '2026-11-23 12:00:00'),
    );
    DB::table('cleaning_work_pause_requests')->insert([
        'cleaning_booking_session_id' => $visit->id,
        'pending_session_id' => $visit->id,
        'command_key' => 'pause-1',
        'requested_by' => $visit->booking->customer_id,
        'expected_clock_revision' => 2,
        'status' => 'pending',
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $visit->forceFill(['clock_revision' => 2, 'status' => 'in_progress'])->save();

    $clock = app(CleaningV3WorkClockProjectionService::class)->project(
        $visit->fresh(), CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC'),
    );

    expect($clock['state'])->toBe('running')
        ->and($clock['activeSeconds'])->toBe(7200)
        ->and($clock['activeSince'])->toBe('2026-11-23T12:00:00+00:00')
        ->and($clock['pausePending'])->toBeTrue()
        ->and($clock['revision'])->toBe(2)
        ->and($clock['canSettle'])->toBeFalse();
});

test('an approved pause leaves clock stopped and completed visits remain immutable read-only projections', function (): void {
    $visit = v3ClockTestVisit();
    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-1', '2026-11-23 10:00:00', '2026-11-23 11:00:00'),
    );

    $visit->update(['status' => 'paused']);
    $clock = app(CleaningV3WorkClockProjectionService::class);
    $now = CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC');

    $paused = $clock->project($visit->fresh(), $now);
    expect($paused['state'])->toBe('paused')
        ->and($paused['activeSeconds'])->toBe(3600)
        ->and($paused['activeSince'])->toBeNull()
        ->and($paused['pausePending'])->toBeFalse();

    $visit->update(['status' => 'completed']);
    $completed = $clock->project($visit->fresh(), $now);
    expect($completed['state'])->toBe('completed')
        ->and($completed['activeSeconds'])->toBe(3600);
});

test('clock projector rejects overlapping or impossible interval histories', function (): void {
    $visit = v3ClockTestVisit();
    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-1', '2026-11-23 10:00:00', '2026-11-23 11:00:00'),
    );
    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-2', '2026-11-23 10:30:00', '2026-11-23 11:30:00'),
    );

    expect(fn () => app(CleaningV3WorkClockProjectionService::class)->project(
        $visit, CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC'),
    ))->toThrow(DomainException::class, 'INVALID_V3_CLOCK_INTERVAL_HISTORY');
});

test('clock read is restricted to the owning customer and the exact V3 session', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $outsider = User::factory()->create(['is_active' => true]);
    $visit = v3ClockTestVisit($customer);
    $unrelated = v3ClockTestVisit($outsider);

    $url = "/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/{$visit->id}/work-clock";

    $this->getJson($url)->assertUnauthorized();

    Sanctum::actingAs($outsider);
    $this->getJson($url)->assertNotFound();

    Sanctum::actingAs($customer);
    $this->getJson($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.state', 'not_started')
        ->assertJsonPath('data.activeSeconds', 0)
        ->assertJsonPath('data.pausePending', false)
        ->assertJsonPath('data.canSettle', false)
        ->assertDontSee('customer_id');

    $this->getJson("/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/{$unrelated->id}/work-clock")
        ->assertNotFound();

    $legacy = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $legacyVisit = $legacy->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $this->getJson("/api/v1/cleaning-bookings/{$legacy->id}/sessions/{$legacyVisit->id}/work-clock")
        ->assertNotFound();
});

test('only an accepted, non-released V3 visit worker can read that visit clock', function (): void {
    $visit = v3ClockTestVisit();
    $otherVisit = v3ClockTestVisit();
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = App\Models\Worker::factory()->create(['user_id' => $workerUser->id]);
    $url = "/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/{$visit->id}/work-clock";

    Sanctum::actingAs($workerUser);
    $this->getJson($url)->assertNotFound();

    $assignment = $visit->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'pending',
    ]);
    $this->getJson($url)->assertNotFound();

    $assignment->update(['status' => 'accepted', 'accepted_at' => now()]);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.visitId', $visit->id)
        ->assertJsonPath('data.state', 'not_started')
        ->assertDontSee('customer_id');

    $this->getJson("/api/v1/cleaning-bookings/{$otherVisit->cleaning_booking_id}/sessions/{$otherVisit->id}/work-clock")
        ->assertNotFound();

    $assignment->forceFill(['released_at' => now()])->save();
    $this->getJson($url)->assertNotFound();
});

test('only the current, explicitly assigned supervisor can read a V3 team clock', function (): void {
    Spatie\Permission\Models\Role::findOrCreate('Cleaning Supervisor', 'web');

    $visit = v3ClockTestVisit();
    $visit->booking->forceFill(['booking_kind' => 'standard', 'number_of_workers' => 2])->save();

    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $unassigned = User::factory()->create(['is_active' => true]);
    $unassigned->assignRole('Cleaning Supervisor');
    $url = "/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/{$visit->id}/work-clock";

    Sanctum::actingAs($unassigned);
    $this->getJson($url)->assertNotFound();

    $assignmentId = DB::table('cleaning_supervisor_assignments')->insertGetId([
        'cleaning_booking_id' => $visit->cleaning_booking_id,
        'supervisor_user_id' => $supervisor->id,
        'active_slot' => 1,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($supervisor);
    $this->getJson($url)->assertNotFound();
    \Tests\Support\CleaningV3AdmittedStateFixture::apply($visit->booking);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.visitId', $visit->id)
        ->assertJsonPath('data.activeSeconds', 0)
        ->assertDontSee('customer_id');

    DB::table('cleaning_supervisor_assignments')->where('id', $assignmentId)->update([
        'active_slot' => null,
        'released_at' => now(),
    ]);

    $this->getJson($url)->assertNotFound();
});

test('clock refuses mismatches between the persisted visit state and active interval history', function (): void {
    $service = app(CleaningV3WorkClockProjectionService::class);
    $now = CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC');
    $visit = v3ClockTestVisit();

    $visit->update(['status' => 'in_progress']);
    expect(fn () => $service->project($visit->fresh(), $now))
        ->toThrow(DomainException::class, 'INVALID_V3_CLOCK_VISIT_STATE');

    $visit->update(['status' => 'paused']);
    expect(fn () => $service->project($visit->fresh(), $now))
        ->toThrow(DomainException::class, 'INVALID_V3_CLOCK_VISIT_STATE');

    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-1', '2026-11-23 10:00:00'),
    );

    $visit->update(['status' => 'scheduled']);
    expect(fn () => $service->project($visit->fresh(), $now))
        ->toThrow(DomainException::class, 'INVALID_V3_CLOCK_VISIT_STATE');

    $visit->update(['status' => 'completed']);
    expect(fn () => $service->project($visit->fresh(), $now))
        ->toThrow(DomainException::class, 'INVALID_V3_CLOCK_VISIT_STATE');
});

test('terminal and non-running V3 clock states are never mislabeled as completed or paused', function (): void {
    $visit = v3ClockTestVisit();
    $clock = app(CleaningV3WorkClockProjectionService::class);
    $now = CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC');

    foreach (['cancelled', 'skipped', 'superseded', 'completed'] as $terminal) {
        $visit->update(['status' => $terminal]);
        expect($clock->project($visit->fresh(), $now)['state'])->toBe($terminal);
    }

    DB::table('cleaning_work_intervals')->insert(
        v3ClockInterval($visit, 'start-1', '2026-11-23 10:00:00', '2026-11-23 11:00:00'),
    );
    $visit->update(['status' => 'awaiting_customer_completion']);
    $result = $clock->project($visit->fresh(), $now);

    expect($result['state'])->toBe('not_running')
        ->and($result['activeSeconds'])->toBe(3600)
        ->and($result['pausePending'])->toBeFalse();
});

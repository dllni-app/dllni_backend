<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;

function supervisorUser(bool $active = true): User
{
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $user = User::factory()->create(['phone' => fake()->unique()->numerify('09########'), 'is_active' => $active]);
    $user->assignRole('Cleaning Supervisor');

    return $user;
}

function supervisorBooking(User $supervisor, array $attributes = []): CleaningBooking
{
    $booking = CleaningBooking::factory()->create(array_merge([
        'booking_kind' => 'supervised_team', 'number_of_workers' => 3,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ], $attributes));
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $booking;
}

it('requires supervisor credentials and rejects inactive supervisors', function () {
    $supervisor = supervisorUser();
    $ordinary = User::factory()->create(['phone' => '0911111111', 'is_active' => true]);
    $inactive = supervisorUser(false);

    $this->postJson('/api/v1/supervisor/login', ['phone' => $ordinary->phone, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $this->postJson('/api/v1/supervisor/login', ['phone' => $inactive->phone, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $login = $this->postJson('/api/v1/supervisor/login', ['phone' => $supervisor->phone, 'password' => 'password'])
        ->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'phone']]]);

    $this->getJson('/api/v1/supervisor/me')->assertUnauthorized();
    Sanctum::actingAs($ordinary);
    $this->getJson('/api/v1/supervisor/me')->assertForbidden();
    Sanctum::actingAs($supervisor);
    $this->getJson('/api/v1/supervisor/me')->assertOk()->assertJsonPath('data.role', 'supervisor');
    expect($login->json('data.token'))->toBeString();
});

it('uses the issued Sanctum token for profile and revokes it on logout', function () {
    $supervisor = supervisorUser();
    $token = $this->postJson('/api/v1/supervisor/login', [
        'phone' => $supervisor->phone, 'password' => 'password',
    ])->assertOk()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/supervisor/me')
        ->assertOk()->assertJsonPath('data.id', $supervisor->id);
    $this->withToken($token)->postJson('/api/v1/supervisor/logout')->assertOk()
        ->assertJsonPath('data.loggedOut', true);
    $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'supervisor-app']);
});

it('scopes lists and details to the active supervisor and explicit service kind', function () {
    $first = supervisorUser();
    $second = supervisorUser();
    $mine = supervisorBooking($first, ['booking_number' => 'TEAM-ONE', 'scheduled_date' => '2026-10-16']);
    $theirs = supervisorBooking($second, ['booking_number' => 'TEAM-TWO', 'scheduled_date' => '2026-10-16']);
    $legacy = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 3]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $legacy->id, 'supervisor_user_id' => $first->id,
        'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($first);

    $this->getJson('/api/v1/supervisor/bookings?search=TEAM-ONE&date=2026-10-16&perPage=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
    $this->getJson('/api/v1/supervisor/home')->assertOk()->assertJsonPath('data.todayTotal', 1);
    $this->getJson('/api/v1/supervisor/bookings/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/v1/supervisor/bookings/'.$legacy->id)->assertNotFound();
    $this->getJson('/api/v1/supervisor/schedule?date=2026-10-16')->assertOk()->assertJsonCount(1, 'data');

    DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $mine->id)
        ->update(['released_at' => now(), 'active_slot' => null]);
    $this->getJson('/api/v1/supervisor/bookings/'.$mine->id)->assertNotFound();
});

it('derives two of three readiness from accepted arrived assignments', function () {
    $supervisor = supervisorUser();
    $booking = supervisorBooking($supervisor);
    foreach ([true, true, false] as $arrived) {
        $worker = Worker::factory()->create();
        $booking->workerAssignments()->create([
            'worker_id' => $worker->id, 'status' => 'accepted',
            'accepted_at' => now(), 'arrived_at' => $arrived ? now() : null,
        ]);
    }
    Sanctum::actingAs($supervisor);

    $this->getJson('/api/v1/supervisor/bookings/'.$booking->id.'/attendance')
        ->assertOk()->assertJsonPath('data.requiredWorkers', 3)
        ->assertJsonPath('data.acceptedWorkers', 3)->assertJsonPath('data.arrivedWorkers', 2)
        ->assertJsonPath('data.teamReady', false)->assertJsonCount(3, 'data.workers');
    $this->getJson('/api/v1/supervisor/bookings/'.$booking->id.'/start')
        ->assertMethodNotAllowed();
    $this->postJson('/api/v1/supervisor/bookings/'.$booking->id.'/start')
        ->assertStatus(409)->assertJsonPath('code', 'FEATURE_NOT_CONFIGURED');
    $this->assertDatabaseHas('cleaning_bookings', ['id' => $booking->id, 'work_started_at' => null]);
    $booking->workerAssignments()->whereNull('arrived_at')->update(['arrived_at' => now()]);
    $this->getJson('/api/v1/supervisor/bookings/'.$booking->id.'/attendance')
        ->assertOk()->assertJsonPath('data.arrivedWorkers', 3)->assertJsonPath('data.teamReady', true);
});

it('records scoped incidents and replacement requests without altering worker assignments', function () {
    $supervisor = supervisorUser();
    $other = supervisorUser();
    $booking = supervisorBooking($supervisor);
    $assignment = $booking->workerAssignments()->create(['worker_id' => Worker::factory()->create()->id, 'status' => 'accepted']);
    Sanctum::actingAs($other);
    $this->postJson('/api/v1/supervisor/bookings/'.$booking->id.'/attendance-incidents', [
        'type' => 'absent', 'description' => 'Worker was absent', 'workerAssignmentId' => $assignment->id,
    ])->assertNotFound();
    Sanctum::actingAs($supervisor);
    $this->postJson('/api/v1/supervisor/bookings/'.$booking->id.'/attendance-incidents', [
        'type' => 'absent', 'description' => 'Worker was absent', 'workerAssignmentId' => $assignment->id,
    ])->assertCreated()->assertJsonPath('data.status', 'open');
    $this->postJson('/api/v1/supervisor/bookings/'.$booking->id.'/replacement-requests', [
        'workerAssignmentId' => $assignment->id, 'reason' => 'Worker did not arrive',
    ])->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->getJson('/api/v1/supervisor/bookings/'.$booking->id.'/incidents')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/supervisor/bookings/'.$booking->id.'/timeline')->assertOk()->assertJsonCount(2, 'data');
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', ['id' => $assignment->id, 'status' => 'accepted', 'arrived_at' => null]);
    $this->postJson('/api/v1/supervisor/bookings/'.$booking->id.'/replacement-requests', [
        'workerAssignmentId' => $assignment->id, 'reason' => 'Worker did not arrive',
    ])->assertCreated();
    expect(DB::table('cleaning_supervisor_replacements')->where('cleaning_booking_id', $booking->id)->count())->toBe(1);
});

it('enforces one active supervisor per booking while retaining released history', function () {
    $first = supervisorUser();
    $second = supervisorUser();
    $booking = supervisorBooking($first);
    try {
        DB::table('cleaning_supervisor_assignments')->insert([
            'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $second->id,
            'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        test()->fail('A second active supervisor was accepted.');
    } catch (QueryException) {
        // Database uniqueness is the final concurrency boundary.
    }
    DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)
        ->update(['active_slot' => null, 'released_at' => now()]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $second->id,
        'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)->count())->toBe(2);
});

it('lets operations assign a supervisor and validates replacement reason', function () {
    $manager = User::factory()->create(['is_active' => true]);
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $manager->assignRole('Cleaning Ops Manager');
    $first = supervisorUser();
    $second = supervisorUser();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team', 'status' => CleaningBookingStatus::Pending,
    ]);
    $standard = CleaningBooking::factory()->create(['booking_kind' => 'standard']);

    Sanctum::actingAs($first);
    $this->postJson('/api/v1/admin/cleaning/supervised-bookings/'.$booking->id.'/supervisor', [
        'supervisorUserId' => $first->id,
    ])->assertForbidden();
    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/admin/cleaning/supervised-bookings/'.$standard->id.'/supervisor', [
        'supervisorUserId' => $first->id,
    ])->assertNotFound();
    $this->postJson('/api/v1/admin/cleaning/supervised-bookings/'.$booking->id.'/supervisor', [
        'supervisorUserId' => $first->id,
    ])->assertCreated()->assertJsonPath('data.unchanged', false);
    $this->postJson('/api/v1/admin/cleaning/supervised-bookings/'.$booking->id.'/supervisor', [
        'supervisorUserId' => $first->id,
    ])->assertOk()->assertJsonPath('data.unchanged', true);
    $this->postJson('/api/v1/admin/cleaning/supervised-bookings/'.$booking->id.'/supervisor', [
        'supervisorUserId' => $second->id,
    ])->assertStatus(422);
    $this->assertDatabaseHas('cleaning_supervisor_activity', [
        'cleaning_booking_id' => $booking->id, 'actor_user_id' => $manager->id,
        'action' => 'supervisor_assigned',
    ]);
    expect(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)->count())->toBe(1);
});

it('blocks legacy worker execution actions for supervised bookings', function () {
    $supervisor = supervisorUser();
    $booking = supervisorBooking($supervisor);
    $worker = Worker::factory()->create();
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id, 'status' => 'accepted', 'arrived_at' => now(),
    ]);
    Sanctum::actingAs($worker->user);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")->assertForbidden();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete")->assertForbidden();
});


it('allows operations to assign a supervisor with an audited active slot', function () {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $operations = User::factory()->create(['is_active' => true]);
    $operations->assignRole('Cleaning Ops Manager');
    $supervisor = supervisorUser();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'estimated_hours' => 12,
        'number_of_workers' => 3,
        'scheduled_date' => '2026-10-20',
        'scheduled_time' => '09:00',
    ]);
    Sanctum::actingAs($operations);
    $url = "/api/v1/admin/cleaning/supervised-bookings/{$booking->id}/supervisor";
    $this->postJson($url, ['supervisorUserId' => $supervisor->id])
        ->assertCreated()->assertJsonPath('data.supervisorUserId', $supervisor->id);
    $this->postJson($url, ['supervisorUserId' => $supervisor->id])
        ->assertOk()->assertJsonPath('data.unchanged', true);
    $this->assertDatabaseCount('cleaning_supervisor_assignments', 1);
    $this->assertDatabaseHas('cleaning_supervisor_activity', [
        'cleaning_booking_id' => $booking->id, 'action' => 'supervisor_assigned',
    ]);
    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")->assertOk();
});

it('blocks overlapping supervisor assignments and allows separate appointments', function () {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $operations = User::factory()->create(['is_active' => true]);
    $operations->assignRole('Cleaning Ops Manager');
    $supervisor = supervisorUser();
    Sanctum::actingAs($operations);
    $url = '/api/v1/admin/cleaning/supervised-bookings/';
    $makeBooking = fn (string $time) => CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team', 'number_of_workers' => 3,
        'estimated_hours' => 12, 'scheduled_date' => '2026-10-20', 'scheduled_time' => $time,
    ]);
    $first = $makeBooking('09:00');
    $overlap = $makeBooking('12:00');
    $later = $makeBooking('14:00');
    $this->postJson($url.$first->id.'/supervisor', ['supervisorUserId' => $supervisor->id])
        ->assertCreated();
    $this->postJson($url.$overlap->id.'/supervisor', ['supervisorUserId' => $supervisor->id])
        ->assertStatus(409);
    $this->postJson($url.$later->id.'/supervisor', ['supervisorUserId' => $supervisor->id])
        ->assertCreated();
    $this->assertDatabaseCount('cleaning_supervisor_assignments', 2);
});

it('requires authorization and a reason to replace the current supervisor', function () {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $operations = User::factory()->create(['is_active' => true]);
    $operations->assignRole('Cleaning Ops Manager');
    $first = supervisorUser();
    $second = supervisorUser();
    $booking = supervisorBooking($first, [
        'estimated_hours' => 12, 'scheduled_date' => '2026-10-22', 'scheduled_time' => '09:00',
    ]);
    $url = "/api/v1/admin/cleaning/supervised-bookings/{$booking->id}/supervisor";
    Sanctum::actingAs($first);
    $this->postJson($url, ['supervisorUserId' => $second->id])->assertForbidden();
    Sanctum::actingAs($operations);
    $this->postJson($url, ['supervisorUserId' => $second->id])->assertStatus(422);
    $this->postJson($url, [
        'supervisorUserId' => $second->id, 'reason' => 'Supervisor unavailable',
    ])->assertCreated();
    $this->assertDatabaseHas('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $first->id, 'active_slot' => null,
    ]);
    Sanctum::actingAs($first);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")->assertNotFound();
    Sanctum::actingAs($second);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")->assertOk();
});

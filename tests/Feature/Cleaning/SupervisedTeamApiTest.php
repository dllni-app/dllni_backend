<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;
use Spatie\Permission\Models\Role;

function supervisedTestBooking(int $workers = 2): CleaningBooking
{
    $day = now()->addDay()->startOfDay();

    return CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'status' => CleaningBookingStatus::Pending,
        'supervised_state' => 'staffing',
        'number_of_workers' => $workers,
        'estimated_hours' => 12,
        'scheduled_date' => $day->toDateString(),
        'scheduled_time' => '09:00',
        'supervised_base_minutes' => 720,
        'supervised_duration_minutes' => (int) ceil(720 / $workers),
        'supervised_scheduled_end_at' => $day->copy()->setTime(9, 0)->addMinutes((int) ceil(720 / $workers)),
        'supervised_original_end_at' => $day->copy()->setTime(9, 0)->addMinutes((int) ceil(720 / $workers)),
    ]);
}

function supervisorTestUser(CleaningBooking $booking): User
{
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true, 'password' => Hash::make('supervisor-pass')]);
    $supervisor->assignRole('Cleaning Supervisor');
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $supervisor;
}

it('keeps supervisor reading scoped and requires a supervisor role', function (): void {
    $booking = supervisedTestBooking();
    $supervisor = supervisorTestUser($booking);
    $other = supervisedTestBooking();

    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")
        ->assertOk()->assertJsonPath('data.serviceType', 'supervised_team');
    $this->getJson("/api/v1/supervisor/bookings/{$other->id}")->assertNotFound();

    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson('/api/v1/supervisor/bookings')->assertForbidden();
});

it('calculates the supervised interval from total work divided by worker count', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => '09:00',
        'number_of_workers' => 3, 'estimated_hours' => 12,
    ]);
    $converted = app(SupervisedTeamService::class)->initialize($booking);
    expect($converted->booking_kind)->toBe('supervised_team')
        ->and($converted->supervised_duration_minutes)->toBe(240)
        ->and($converted->supervised_scheduled_end_at->format('H:i'))->toBe('13:00')
        ->and($converted->supervised_original_end_at->format('H:i'))->toBe('13:00');
});

it('blocks legacy worker start, code and completion actions for supervised bookings', function (): void {
    $booking = supervisedTestBooking();
    $worker = Worker::factory()->create();
    Sanctum::actingAs($worker->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code")->assertForbidden();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")->assertForbidden();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete")->assertForbidden();
});

it('enforces team readiness and completes supervisor verification and customer handover', function (): void {
    $booking = supervisedTestBooking();
    $supervisor = supervisorTestUser($booking);
    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/verification/request")->assertStatus(409);

    for ($i = 0; $i < 2; $i++) {
        $worker = Worker::factory()->create();
        $booking->workerAssignments()->create([
            'worker_id' => $worker->id, 'status' => 'accepted', 'accepted_at' => now(),
            'arrived_at' => now(),
        ]);
    }

    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/verification/request")
        ->assertOk()->assertJsonPath('data.state', 'verification_pending');
    Sanctum::actingAs($booking->customer);
    $code = $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}/supervised/verification/code")
        ->assertOk()->json('data.code');
    expect($code)->toMatch('/^[0-9]{6}$/');

    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/verification/confirm", ['code' => 'abcdef'])
        ->assertUnprocessable();
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/verification/confirm", ['code' => $code])
        ->assertOk()->assertJsonPath('data.teamState', 'verified');
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/start")
        ->assertOk()->assertJsonPath('data.teamState', 'in_progress');
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/handover-request")
        ->assertOk()->assertJsonPath('data.teamState', 'handover_pending');

    Sanctum::actingAs($booking->customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/supervised/handover/decision",
        ['decision' => 'confirm'])->assertOk()->assertJsonPath('data.teamState', 'handover_confirmed');

    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/report",
        ['narrative' => 'تم إنجاز جميع أعمال التنظيف والتسليم للعميل بنجاح.'])
        ->assertOk()->assertJsonPath('data.teamState', 'completed');
    expect(DB::table('cleaning_supervisor_reports')->where('cleaning_booking_id', $booking->id)->count())->toBe(1);
});

it('rejects invalid codes while preserving the failed attempt counter', function (): void {
    $booking = supervisedTestBooking(2);
    $supervisor = supervisorTestUser($booking);
    foreach ([1, 2] as $i) {
        $worker = Worker::factory()->create();
        $booking->workerAssignments()->create(['worker_id' => $worker->id, 'status' => 'accepted', 'accepted_at' => now(), 'arrived_at' => now()]);
    }
    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/verification/request")->assertOk();
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/verification/confirm", ['code' => '999999'])->assertUnprocessable();
    expect((int) DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $booking->id)->value('attempts'))->toBe(1);
});


it('revokes the former supervisor access after an audited reassignment', function (): void {
    $booking = supervisedTestBooking();
    $first = supervisorTestUser($booking);
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $second = User::factory()->create(['is_active' => true]);
    $second->assignRole('Cleaning Supervisor');
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $manager = User::factory()->create(['is_active' => true]);
    $manager->assignRole('Cleaning Ops Manager');

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/admin/cleaning/supervised/bookings/{$booking->id}/supervisor", [
        'supervisorUserId' => $second->id, 'reason' => 'اختبار إعادة الإسناد',
    ])->assertOk()->assertJsonPath('data.supervisorUserId', $second->id);

    Sanctum::actingAs($first);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")->assertNotFound();
    Sanctum::actingAs($second);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")->assertOk();
    expect(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)->count())->toBe(2);
    expect(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)
        ->where('active_slot', 1)->count())->toBe(1);
});

it('cannot approve a time extension until the customer consents and operations approves', function (): void {
    config()->set('supervised_team.extension_approval_enabled', true);
    $booking = supervisedTestBooking(2);
    $booking->forceFill(['supervised_state' => 'in_progress', 'status' => CleaningBookingStatus::InProgress,
        'work_started_at' => now()])->save();
    $supervisor = supervisorTestUser($booking);
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $manager = User::factory()->create(['is_active' => true]);
    $manager->assignRole('Cleaning Ops Manager');
    $originalEnd = $booking->supervised_original_end_at->timestamp;

    Sanctum::actingAs($supervisor);
    $id = $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/extensions",
        ['additionalMinutes' => 30, 'reason' => 'تحتاج الخدمة إلى نصف ساعة إضافية'])
        ->assertCreated()->json('data.id');

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/admin/cleaning/supervised/extensions/{$id}/decision",
        ['decision' => 'approve'])->assertStatus(409);

    Sanctum::actingAs($booking->customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/supervised/extensions/{$id}/decision",
        ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'pending_operations');

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/admin/cleaning/supervised/extensions/{$id}/decision",
        ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

    $booking->refresh();
    expect($booking->supervised_original_end_at->timestamp)->toBe($originalEnd);
    expect($booking->supervised_scheduled_end_at->timestamp)->toBe($originalEnd + 1800);
});

it('rejects unassigned supervisor media access and foreign worker incident assignment', function (): void {
    $booking = supervisedTestBooking(2);
    $supervisor = supervisorTestUser($booking);
    $other = supervisedTestBooking(2);
    $stranger = User::factory()->create(['is_active' => true]);
    $stranger->assignRole('Cleaning Supervisor');
    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/media")->assertNotFound();

    Sanctum::actingAs($supervisor);
    $worker = Worker::factory()->create();
    $foreign = $other->workerAssignments()->create(['worker_id' => $worker->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/incidents", [
        'type' => 'absent', 'description' => 'تغيب عامل من فريق آخر عن الموعد',
        'workerAssignmentId' => $foreign->id,
    ])->assertStatus(422);
    expect(DB::table('cleaning_supervisor_incidents')->where('cleaning_booking_id', $booking->id)->count())->toBe(0);
});

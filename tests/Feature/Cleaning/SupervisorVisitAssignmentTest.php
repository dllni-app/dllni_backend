<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;
use Modules\Cleaning\Support\CleaningRuntimeSettings;
use Spatie\Permission\Models\Role;

it('adds visit supervision foundation with a zero compensation default', function (): void {
    expect(Schema::hasColumns('cleaning_booking_sessions', [
        'supervision_mode',
        'supervision_initialized_at',
    ]))->toBeTrue()
        ->and(Schema::hasTable('cleaning_booking_session_supervisor_assignments'))->toBeTrue()
        ->and(Schema::hasColumns('cleaning_booking_session_supervisor_assignments', [
            'cleaning_booking_session_id',
            'supervisor_user_id',
            'assigned_by_user_id',
            'assigned_at',
            'released_at',
            'reason',
            'compensation_hourly_rate',
            'active_slot',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_financial_settings', 'supervisor_compensation_hourly_rate'))->toBeTrue()
        ->and((float) CleaningRuntimeSettings::financialDefaults()['supervisor_compensation_hourly_rate'])->toBe(0.0);
});

it('enforces one active supervisor assignment per visit while retaining released history', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $actor = User::factory()->create();
    $firstSupervisor = User::factory()->create();
    $secondSupervisor = User::factory()->create();

    $first = CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $firstSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);

    expect(fn () => CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $secondSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]))->toThrow(QueryException::class);

    $first->update([
        'released_at' => now(),
        'reason' => 'Future visit reassignment after schedule review.',
        'active_slot' => null,
    ]);
    $second = CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $secondSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);

    expect($session->supervisorAssignments()->count())->toBe(2)
        ->and($session->activeSupervisorAssignment()->sole()->id)->toBe($second->id)
        ->and($booking->sessionSupervisorAssignments()->count())->toBe(2);
});

it('scopes initialized visits to active visit ownership instead of booking-level fallback', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $firstSupervisor = User::factory()->create(['is_active' => true]);
    $secondSupervisor = User::factory()->create(['is_active' => true]);
    $firstSupervisor->assignRole('Cleaning Supervisor');
    $secondSupervisor->assignRole('Cleaning Supervisor');
    $actor = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'bedroom.medium.1',
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'display_label' => 'Bedroom',
        'weight' => 1,
    ]);
    $sessions = collect([1, 2])->map(function (int $sequence) use ($booking, $room) {
        $date = now(config('app.timezone'))->addDays($sequence + 1)->toDateString();
        $session = $booking->sessions()->create([
            'sequence' => $sequence,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $date,
            'scheduled_time' => '09:00',
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'schedule.'.$sequence,
            'visit_key' => 'visit.'.$sequence,
            'timezone' => 'Asia/Damascus',
            'starts_at' => Carbon\CarbonImmutable::parse("{$date} 09:00", 'Asia/Damascus')->utc(),
            'ends_at' => Carbon\CarbonImmutable::parse("{$date} 11:00", 'Asia/Damascus')->utc(),
            'required_workers' => 2,
            'work_minutes' => 240,
            'duration_minutes' => 120,
        ]);

        return $session;
    });
    $booking->supervisorAssignments()->create([
        'supervisor_user_id' => $firstSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'active_slot' => 1,
    ]);
    $firstVisit = CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $sessions[0]->id,
        'supervisor_user_id' => $firstSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $sessions[1]->id,
        'supervisor_user_id' => $secondSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);
    $scheduled = app(SupervisorScheduledV2Service::class);

    expect($scheduled->assignedQuery($firstSupervisor)->whereKey($booking->id)->exists())->toBeTrue()
        ->and($scheduled->assignedSessions($firstSupervisor, $booking)->pluck('id')->all())
        ->toBe([(int) $sessions[0]->id])
        ->and($scheduled->assignedSessions($secondSupervisor, $booking)->pluck('id')->all())
        ->toBe([(int) $sessions[1]->id]);

    $firstVisit->update(['active_slot' => null, 'released_at' => now()]);
    expect($scheduled->assignedQuery($firstSupervisor)->whereKey($booking->id)->exists())->toBeFalse();
});

it('rejects visit supervisor reassignment after worker acceptance or arrival evidence', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $firstSupervisor = User::factory()->create(['is_active' => true]);
    $secondSupervisor = User::factory()->create(['is_active' => true]);
    $firstSupervisor->assignRole('Cleaning Supervisor');
    $secondSupervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'worker_assigned',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(3)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'worker_assigned',
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'assignment-freeze-room',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Assignment freeze room',
        'weight' => 1,
    ]);
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'assignment-freeze.schedule',
        'visit_key' => 'assignment-freeze.visit',
        'timezone' => 'Asia/Damascus',
        'starts_at' => Carbon\CarbonImmutable::parse(
            $session->scheduled_date->toDateString().' 09:00',
            'Asia/Damascus',
        )->utc(),
        'ends_at' => Carbon\CarbonImmutable::parse(
            $session->scheduled_date->toDateString().' 11:00',
            'Asia/Damascus',
        )->utc(),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);
    $session->supervisorAssignments()->create([
        'supervisor_user_id' => $firstSupervisor->id,
        'assigned_by_user_id' => $ops->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);
    $session->workerAssignments()->create([
        'worker_id' => App\Models\Worker::factory()->create()->id,
        'status' => 'accepted',
        'accepted_at' => now(),
        'arrived_at' => now(),
    ]);
    Sanctum::actingAs($ops);

    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$booking->id}/visits/1/supervisor", [
        'supervisorUserId' => $secondSupervisor->id,
        'reason' => 'Supervisor schedule changed after assignment.',
    ])->assertStatus(409);
    expect($session->supervisorAssignments()->count())->toBe(1)
        ->and((int) $session->activeSupervisorAssignment()->value('supervisor_user_id'))->toBe($firstSupervisor->id);
});

it('enforces initialized visit privacy through real supervisor list detail and visit APIs', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $legacySupervisor = User::factory()->create(['is_active' => true]);
    $visitSupervisor = User::factory()->create(['is_active' => true]);
    $legacySupervisor->assignRole('Cleaning Supervisor');
    $visitSupervisor->assignRole('Cleaning Supervisor');
    $actor = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'scheduled_date' => now(config('app.timezone'))->addDay()->toDateString(),
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'billableMinutes' => 240, 'fee' => 0]],
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'bedroom.medium.1',
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'display_label' => 'Bedroom',
        'weight' => 1,
    ]);
    $sessions = collect([1, 2])->map(function (int $sequence) use ($booking, $room) {
        $date = now(config('app.timezone'))->addDays($sequence + 2)->toDateString();
        $session = $booking->sessions()->create([
            'sequence' => $sequence,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $date,
            'scheduled_time' => $sequence === 1 ? '09:00' : '14:00',
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'privacy.schedule.'.$sequence,
            'visit_key' => 'privacy.visit.'.$sequence,
            'timezone' => 'Asia/Damascus',
            'starts_at' => Carbon\CarbonImmutable::parse(
                $date.' '.($sequence === 1 ? '09:00' : '14:00'),
                'Asia/Damascus',
            )->utc(),
            'ends_at' => Carbon\CarbonImmutable::parse(
                $date.' '.($sequence === 1 ? '11:00' : '16:00'),
                'Asia/Damascus',
            )->utc(),
            'required_workers' => 2,
            'work_minutes' => 240,
            'duration_minutes' => 120,
        ]);

        return $session;
    });
    $booking->supervisorAssignments()->create([
        'supervisor_user_id' => $legacySupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'active_slot' => 1,
    ]);

    Sanctum::actingAs($legacySupervisor);
    $this->getJson('/api/v1/supervisor/v2/bookings')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonMissing(['id' => $booking->id]);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertNotFound();
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")->assertNotFound();

    $owned = CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $sessions[1]->id,
        'supervisor_user_id' => $legacySupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $sessions[0]->id,
        'supervisor_user_id' => $visitSupervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 0,
        'active_slot' => 1,
    ]);

    $this->getJson('/api/v1/supervisor/v2/bookings')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $booking->id)
        ->assertJsonPath('data.0.visitCount', 1)
        ->assertJsonPath('data.0.scheduledDate', $sessions[1]->scheduled_date->toDateString());
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.visits')
        ->assertJsonPath('data.visitCount', 1)
        ->assertJsonPath('data.scheduledDate', $sessions[1]->scheduled_date->toDateString())
        ->assertJsonPath('data.visits.0.sequence', 2)
        ->assertJsonMissing(['sequence' => 1]);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")->assertNotFound();
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/2")
        ->assertOk()
        ->assertJsonPath('data.sequence', 2);
    $this->getJson('/api/v1/supervisor/v2/bookings?date='.$sessions[0]->scheduled_date->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/supervisor/v2/bookings?date='.$sessions[1]->scheduled_date->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.visitCount', 1);

    $owned->update(['active_slot' => null, 'released_at' => now()]);
    $this->getJson('/api/v1/supervisor/v2/bookings')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertNotFound();
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/2")->assertNotFound();
});

it('assigns and exposes canonical open time supervisor visits without standard pricing metadata', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time',
        'number_of_workers' => 2,
        'status' => 'pending',
        'property_details' => [],
    ]);
    $date = now('Asia/Damascus')->addDays(4)->toDateString();
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'actual_time',
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'open_time_expected_max_minutes' => 120,
        'open_time_hard_max_minutes' => 480,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => 2,
            'expectedMaxMinutes' => 120,
            'currency' => 'SYP',
        ],
    ]);

    Sanctum::actingAs($ops);
    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$booking->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated()
        ->assertJsonPath('data.visitSequence', 1)
        ->assertJsonPath('data.supervisorUserId', $supervisor->id);

    Sanctum::actingAs($supervisor);
    $this->getJson('/api/v1/supervisor/v2/bookings')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $booking->id)
        ->assertJsonPath('data.0.visitCount', 1);
    $expectedStart = CarbonImmutable::parse($date.' 09:00', 'Asia/Damascus');
    $expectedEnd = CarbonImmutable::parse($date.' 11:00', 'Asia/Damascus');
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.visits.0.sequence', 1)
        ->assertJsonPath('data.visits.0.scheduledStartAt', $expectedStart->utc()->toIso8601String())
        ->assertJsonPath('data.visits.0.scheduledEndAt', $expectedEnd->utc()->toIso8601String());
});

it('uses canonical open time expected max minutes for half open supervisor conflict windows', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $bulkSupervisor = User::factory()->create(['is_active' => true]);
    $bulkSupervisor->assignRole('Cleaning Supervisor');
    $date = now(config('app.timezone'))->addDays(5)->toDateString();

    $makeBooking = function (string $time, int $minutes) use ($date): CleaningBooking {
        $booking = CleaningBooking::factory()->create([
            'booking_kind' => 'open_time',
            'number_of_workers' => 2,
            'status' => 'pending',
            'property_details' => [],
        ]);
        $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
            'calculation_mode' => 'actual_time',
            'scheduled_date' => $date,
            'scheduled_time' => $time,
            'duration_hours' => round($minutes / 60, 2),
            'open_time_expected_max_minutes' => $minutes,
            'open_time_hard_max_minutes' => 480,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
            'pricing_snapshot' => [
                'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
                'workerCount' => 2,
                'expectedMaxMinutes' => $minutes,
                'currency' => 'SYP',
            ],
        ]);

        return $booking;
    };

    $first = $makeBooking('09:00', 120);
    $touching = $makeBooking('11:00', 90);
    $overlap = $makeBooking('10:59', 90);
    Sanctum::actingAs($ops);

    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$first->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated();
    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$touching->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated();
    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$overlap->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertStatus(409);
});

it('detects standard and open time conflicts in the configured cleaning timezone', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('cleaning_booking_v2.timezone'))->toBe('Asia/Damascus');

    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $date = now('Asia/Damascus')->addDays(7)->toDateString();

    $standard = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
    ]);
    $room = $standard->rooms()->create([
        'room_key' => 'timezone-standard-room',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Timezone Standard Room',
        'weight' => 1,
    ]);
    $standardSession = $standard->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $standardStart = Carbon\CarbonImmutable::parse($date.' 09:00', 'Asia/Damascus')->utc();
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $standard->id,
        'cleaning_booking_session_id' => $standardSession->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'timezone.standard.schedule',
        'visit_key' => 'timezone.standard.visit',
        'timezone' => 'Asia/Damascus',
        'starts_at' => $standardStart,
        'ends_at' => $standardStart->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);

    $makeOpenTime = function (string $time) use ($date): CleaningBooking {
        $booking = CleaningBooking::factory()->create([
            'booking_kind' => 'open_time',
            'number_of_workers' => 2,
            'status' => 'pending',
            'property_details' => [],
        ]);
        $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
            'calculation_mode' => 'actual_time',
            'scheduled_date' => $date,
            'scheduled_time' => $time,
            'duration_hours' => 2,
            'open_time_expected_max_minutes' => 120,
            'open_time_hard_max_minutes' => 480,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
            'pricing_snapshot' => [
                'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
                'workerCount' => 2,
                'expectedMaxMinutes' => 120,
                'currency' => 'SYP',
            ],
        ]);

        return $booking;
    };

    $overlap = $makeOpenTime('09:00');
    $touching = $makeOpenTime('11:00');
    Sanctum::actingAs($ops);
    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$standard->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated();
    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$overlap->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertStatus(409);
    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$touching->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated();
});

it('blocks legacy supervised-team overlap with canonical open time and allows touching endpoints', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $date = now('Asia/Damascus')->addDays(9)->toDateString();

    $openTime = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time',
        'number_of_workers' => 2,
        'status' => 'pending',
        'property_details' => [],
    ]);
    $openTime->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'actual_time',
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'open_time_expected_max_minutes' => 120,
        'open_time_hard_max_minutes' => 480,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'pricing_snapshot' => [
            'scheduleType' => CleaningBookingSession::TYPE_OPEN_TIME,
            'workerCount' => 2,
            'expectedMaxMinutes' => 120,
            'currency' => 'SYP',
        ],
    ]);
    $overlapLegacy = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'number_of_workers' => 3,
        'status' => 'worker_assigned',
        'estimated_hours' => 6,
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
    ]);
    $touchingLegacy = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'number_of_workers' => 3,
        'status' => 'worker_assigned',
        'estimated_hours' => 6,
        'scheduled_date' => $date,
        'scheduled_time' => '11:00',
    ]);
    Sanctum::actingAs($ops);

    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$openTime->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated();
    $this->postJson("/api/v1/admin/cleaning/supervised-bookings/{$overlapLegacy->id}/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertStatus(409);
    $this->postJson("/api/v1/admin/cleaning/supervised-bookings/{$touchingLegacy->id}/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertCreated();
});

it('locks the supervisor principal before eligibility and session reads in visit and bulk assignment paths', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $bulkSupervisor = User::factory()->create(['is_active' => true]);
    $bulkSupervisor->assignRole('Cleaning Supervisor');

    $makeBooking = function (): CleaningBooking {
        $booking = CleaningBooking::factory()->create([
            'booking_kind' => 'standard',
            'number_of_workers' => 2,
            'status' => 'pending',
            'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
        ]);
        $room = $booking->rooms()->create([
            'room_key' => fake()->uuid(),
            'room_type' => 'bedroom',
            'room_size' => 'small',
            'display_label' => 'Lock order room',
            'weight' => 1,
        ]);
        $date = now(config('app.timezone'))->addDays(8)->toDateString();
        $session = $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $date,
            'scheduled_time' => '09:00',
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
        $start = Carbon\CarbonImmutable::parse($date.' 09:00', 'Asia/Damascus')->utc();
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'lock-order.'.$booking->id,
            'visit_key' => 'lock-order.'.$booking->id,
            'timezone' => 'Asia/Damascus',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'required_workers' => 2,
            'work_minutes' => 240,
            'duration_minutes' => 120,
        ]);

        return $booking->fresh();
    };

    $assertLockOrder = function (callable $assign): void {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $assign();
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql): string => mb_strtolower($sql))->values();
        DB::disableQueryLog();

        $bookingIndex = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "cleaning_bookings"'));
        $supervisorIndex = $queries->search(fn (string $sql, int $index): bool => $bookingIndex !== false
            && $index > $bookingIndex
            && str_contains($sql, 'from "users"'));
        $eligibilityIndex = $queries->search(fn (string $sql, int $index): bool => $bookingIndex !== false
            && $index > $bookingIndex
            && (str_contains($sql, 'cleaning_room_schedules')
                || str_contains($sql, 'cleaning_room_periods')
                || str_contains($sql, 'cleaning_booking_sessions')));

        expect($bookingIndex)->not->toBeFalse()
            ->and($supervisorIndex)->not->toBeFalse()
            ->and($eligibilityIndex)->not->toBeFalse()
            ->and($supervisorIndex)->toBeLessThan($eligibilityIndex);
    };

    $visitBooking = $makeBooking();
    $assertLockOrder(fn () => app(CleaningSupervisorAssignmentService::class)->assignVisit(
        $ops,
        $visitBooking->id,
        1,
        $supervisor->id,
        null,
    ));

    $bulkBooking = $makeBooking();
    $assertLockOrder(fn () => app(CleaningSupervisorAssignmentService::class)->assign(
        $ops,
        $bulkBooking->id,
        $bulkSupervisor->id,
        null,
        true,
    ));
});

it('rejects visit supervisor assignment when the locked parent booking is terminal', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'completed',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'terminal-parent-room',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Terminal parent room',
        'weight' => 1,
    ]);
    $date = now(config('app.timezone'))->addDays(9)->toDateString();
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $start = Carbon\CarbonImmutable::parse($date.' 09:00', 'Asia/Damascus')->utc();
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'terminal-parent.schedule',
        'visit_key' => 'terminal-parent.visit',
        'timezone' => 'Asia/Damascus',
        'starts_at' => $start,
        'ends_at' => $start->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);
    Sanctum::actingAs($ops);

    $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$booking->id}/visits/1/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertStatus(409);

    $this->assertDatabaseMissing('cleaning_booking_session_supervisor_assignments', [
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
    ]);
    $this->assertDatabaseMissing('cleaning_supervisor_activity', [
        'cleaning_booking_id' => $booking->id,
        'action' => 'visit_supervisor_assigned',
    ]);
});

it('rejects a standard visit whose canonical Syria-local schedule has already started', function (): void {
    $frozenNow = CarbonImmutable::parse('2026-10-21 10:00:00', 'Asia/Damascus');
    \Carbon\Carbon::setTestNow($frozenNow);

    try {
        expect(config('app.timezone'))->toBe('UTC')
            ->and(config('cleaning_booking_v2.timezone'))->toBe('Asia/Damascus');

        Role::findOrCreate('Cleaning Ops Manager', 'web');
        Role::findOrCreate('Cleaning Supervisor', 'web');
        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole('Cleaning Ops Manager');
        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('Cleaning Supervisor');
        $booking = CleaningBooking::factory()->create([
            'booking_kind' => 'standard',
            'number_of_workers' => 2,
            'status' => 'pending',
            'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0]],
        ]);
        $room = $booking->rooms()->create([
            'room_key' => 'past-syria-standard-room',
            'room_type' => 'bedroom',
            'room_size' => 'small',
            'display_label' => 'Past Syria Standard Room',
            'weight' => 1,
        ]);
        $session = $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => '2026-10-21',
            'scheduled_time' => '09:00',
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
        $start = CarbonImmutable::parse('2026-10-21 09:00:00', 'Asia/Damascus')->utc();
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'past-syria-standard.schedule',
            'visit_key' => 'past-syria-standard.visit',
            'timezone' => 'Asia/Damascus',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'required_workers' => 2,
            'work_minutes' => 240,
            'duration_minutes' => 120,
        ]);
        Sanctum::actingAs($ops);

        $this->postJson("/api/v1/admin/cleaning/supervisor-v2-bookings/{$booking->id}/visits/1/supervisor", [
            'supervisorUserId' => $supervisor->id,
        ])->assertStatus(409);

        $this->assertDatabaseMissing('cleaning_booking_session_supervisor_assignments', [
            'cleaning_booking_session_id' => $session->id,
            'supervisor_user_id' => $supervisor->id,
        ]);
        $this->assertDatabaseMissing('cleaning_supervisor_activity', [
            'cleaning_booking_id' => $booking->id,
            'action' => 'visit_supervisor_assigned',
        ]);
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

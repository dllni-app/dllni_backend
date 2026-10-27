<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Spatie\Permission\Models\Role;

function scheduledSupervisor(): User
{
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('Cleaning Supervisor');

    return $user;
}

function scheduledV2Booking(string $date = '2026-10-20'): CleaningBooking
{
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => $date, 'scheduled_time' => '09:00',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 1000, 'fee' => 2000]],
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'bedroom.medium.1', 'room_type' => 'bedroom',
        'room_size' => 'medium', 'display_label' => 'غرفة نوم', 'weight' => 1,
    ]);
    foreach ([1 => $date, 2 => CarbonImmutable::parse($date)->addWeek()->toDateString()] as $sequence => $day) {
        $session = $booking->sessions()->create([
            'sequence' => $sequence, 'session_type' => 'recurring_cleaning',
            'calculation_mode' => 'task', 'scheduled_date' => $day,
            'scheduled_time' => '09:00', 'duration_hours' => 2,
            'required_workers' => 2, 'coverage_status' => 'searching', 'status' => 'scheduled',
        ]);
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id, 'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'schedule.'.$sequence, 'visit_key' => 'visit.'.$sequence,
            'timezone' => 'Asia/Damascus',
            'starts_at' => CarbonImmutable::parse("$day 09:00", 'Asia/Damascus')->utc(),
            'ends_at' => CarbonImmutable::parse("$day 11:00", 'Asia/Damascus')->utc(),
            'required_workers' => 2, 'work_minutes' => 240, 'duration_minutes' => 120,
        ]);
        if ($sequence === 1) {
            $session->workerAssignments()->create([
                'worker_id' => Worker::factory()->create()->id,
                'status' => 'accepted', 'accepted_at' => now(), 'arrived_at' => now(),
            ]);
        }
    }

    return $booking;
}

function scheduledAssignableV2Booking(string $date = '2026-10-20'): CleaningBooking
{
    $booking = scheduledV2Booking($date);
    $booking->sessions()->each(function ($session): void {
        $session->workerAssignments()->delete();
        $session->forceFill([
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
        ])->save();
    });

    return $booking->fresh();
}

function assignScheduledSupervisor(User $user, CleaningBooking $booking): void
{
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $user->id,
        'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('restricts V2 visits to active assigned supervisors without exposing customer or unrelated visits', function (): void {
    $owner = scheduledSupervisor();
    $other = scheduledSupervisor();
    $booking = scheduledV2Booking();
    $hidden = scheduledV2Booking('2026-10-21');
    assignScheduledSupervisor($owner, $booking);
    assignScheduledSupervisor($other, $hidden);
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}";

    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs($other);
    $this->getJson($url)->assertNotFound();
    $this->getJson('/api/v1/supervisor/v2/bookings')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonMissing(['id' => $booking->id]);
    Sanctum::actingAs($owner);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.supervisionMode', 'schedule_read_only')
        ->assertJsonCount(2, 'data.visits')
        ->assertJsonPath('data.visits.0.attendance.source', 'session')
        ->assertJsonPath('data.visits.0.attendance.arrivedWorkers', 1)
        ->assertJsonPath('data.visits.0.attendance.teamReady', false)
        ->assertJsonPath('data.visits.1.attendance.arrivedWorkers', 0)
        ->assertJsonPath('data.visits.0.rooms.0.executionStatus', 'not_tracked_per_room')
        ->assertJsonPath('data.allowedActions', [])
        ->assertDontSee('customer_id')->assertDontSee('customerPhone');
    $this->getJson("$url/visits/1")->assertOk()->assertJsonPath('data.sequence', 1);
    $this->getJson("$url/visits/3")->assertNotFound();
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}")->assertNotFound();
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")->assertNotFound();
    Sanctum::actingAs($other);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")->assertNotFound();
    Sanctum::actingAs($owner);
    DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)
        ->update(['active_slot' => null, 'released_at' => now()]);
    $this->getJson($url)->assertNotFound();
});

it('shows visit material prices only to the assigned supervisor', function (): void {
    $owner = scheduledSupervisor();
    $other = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($owner, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    $session->forceFill(['materials_total' => 125.50])->saveQuietly();
    $url = "/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1";

    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs($other);
    $this->getJson($url)->assertNotFound();
    Sanctum::actingAs($owner);
    $this->getJson($url)->assertOk()->assertJsonPath('data.materialsTotal', 125.5);
});

it('reports persisted three-state material sourcing without exposing historical worker to room ownership', function (): void {
    $supervisor = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($supervisor, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    $room = $booking->rooms()->firstOrFail();
    $workerId = $session->workerAssignments()->firstOrFail()->worker_id;
    $room->update(['assigned_worker_id' => $workerId]);
    foreach (['customer_all', 'customer_partial', 'platform_all'] as $index => $choice) {
        DB::table('cleaning_time_periods')->insert([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'period_key' => 'period.'.($index + 1),
            'timezone' => 'Asia/Damascus',
            'starts_at' => now(), 'ends_at' => now()->addHour(),
            'materials_choice' => $choice,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")
        ->assertOk()
        ->assertJsonPath('data.materials.source', null)
        ->assertJsonPath('data.materials.periods.0.source', 'customer_all')
        ->assertJsonPath('data.materials.periods.1.source', 'customer_partial')
        ->assertJsonPath('data.materials.periods.2.source', 'platform_all')
        ->assertJsonMissingPath('data.rooms.0.assignedWorkerId')
        ->assertJsonMissingPath('data.rooms.0.assignedWorkerName')
        ->assertJsonMissingPath('data.rooms.0.assignmentSource');
});

it('projects saved supervisor compensation once per visit regardless of accepted worker count', function (): void {
    $supervisor = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($supervisor, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 1200,
        'active_slot' => 1,
    ]);

    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")
        ->assertOk()
        ->assertJsonPath('data.financials.supervisorHourlyRate', 1200)
        ->assertJsonPath('data.financials.supervisorScheduledMinutes', 120)
        ->assertJsonPath('data.financials.supervisorShareAmount', 2400)
        ->assertJsonPath('data.financials.supervisorCompensationStatus', 'projected')
        ->assertJsonPath('data.financials.isFinal', false);
});

it('exposes persisted visit finances and worker entitlements as provisional without certifying attendance', function (): void {
    $supervisor = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($supervisor, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    $session->forceFill([
        'base_price' => 2000, 'addons_total' => 500,
        'materials_total' => 300, 'special_services_total' => 200,
        'travel_fee' => 150, 'admin_margin_amount' => 400,
        'total_price' => 3050, 'is_pricing_final' => true,
        'pricing_snapshot' => ['currency' => 'SYP'],
    ])->saveQuietly();
    $session->workerAssignments()->firstOrFail()->forceFill([
        'service_share_amount' => 800, 'travel_fee' => 75,
        'admin_margin_amount' => 100, 'worker_amount' => 875, 'currency' => 'SYP',
        'work_started_at' => now(),
    ])->saveQuietly();

    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}/visits/1")
        ->assertOk()
        ->assertJsonPath('data.attendance.plannedWorkers', 2)
        ->assertJsonPath('data.attendance.workStartedWorkers', 1)
        ->assertJsonPath('data.attendance.actualWorkers', null)
        ->assertJsonPath('data.financials.status', 'projected')
        ->assertJsonPath('data.financials.isFinal', false)
        ->assertJsonPath('data.financials.isPricingFinal', true)
        ->assertJsonPath('data.financials.baseServiceTotal', 2000)
        ->assertJsonPath('data.financials.specialServicesTotal', 200)
        ->assertJsonPath('data.financials.materialsTotal', 300)
        ->assertJsonPath('data.financials.supervisorShareAmount', null)
        ->assertJsonPath('data.financials.workers.0.workerAmount', 875)
        ->assertJsonPath('data.financials.workers.0.workerName', $session->workerAssignments()->firstOrFail()->worker->user->name)
        ->assertJsonPath('data.financials.workers.0.isFinal', false);
});

it('denies V2 supervisor mutations with 422 and leaves session attendance unchanged', function (): void {
    $owner = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($owner, $booking);
    Sanctum::actingAs($owner);
    $base = "/api/v1/supervisor/v2/bookings/{$booking->id}";
    $this->postJson("$base/visits/1/start")->assertUnprocessable()
        ->assertJsonPath('code', 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE');
    $this->postJson("$base/visits/2/finish")->assertUnprocessable();
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/start")->assertUnprocessable()
        ->assertJsonPath('code', 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE');
    expect($booking->sessions()->whereNotNull('work_started_at')->count())->toBe(0)
        ->and($booking->sessions()->sum('required_workers'))->toBe(4);
    $this->assertDatabaseCount('cleaning_supervisor_verifications', 0);
});

it('serializes raw room-schedule instants as UTC across application timezones', function (): void {
    $originalAppTimezone = config('app.timezone');
    $originalPhpTimezone = date_default_timezone_get();

    try {
        $owner = scheduledSupervisor();
        $booking = scheduledV2Booking();
        assignScheduledSupervisor($owner, $booking);
        $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
        $firstRoomId = $booking->rooms()->firstOrFail()->id;
        $secondRoomId = $booking->rooms()->create([
            'room_key' => 'bathroom.medium.1', 'room_type' => 'bathroom',
            'room_size' => 'medium', 'display_label' => 'حمام', 'weight' => 1,
        ])->id;

        DB::table('cleaning_room_schedules')->where('cleaning_booking_session_id', $session->id)->delete();
        foreach ([
            ['schedule.gap.first', $firstRoomId, '2026-10-20 06:00:00', '2026-10-20 06:45:00', 45],
            ['schedule.gap.second', $secondRoomId, '2026-10-20 10:00:00', '2026-10-20 11:00:00', 60],
        ] as [$key, $roomId, $start, $end, $minutes]) {
            DB::table('cleaning_room_schedules')->insert([
                'cleaning_booking_id' => $booking->id,
                'cleaning_booking_session_id' => $session->id,
                'cleaning_booking_room_id' => $roomId,
                'schedule_key' => $key,
                'visit_key' => 'visit.1',
                'timezone' => 'Asia/Damascus',
                'starts_at' => $start,
                'ends_at' => $end,
                'required_workers' => 1,
                'work_minutes' => $minutes,
                'duration_minutes' => $minutes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($owner);
        foreach (['UTC', 'Asia/Damascus'] as $appTimezone) {
            config(['app.timezone' => $appTimezone]);
            date_default_timezone_set($appTimezone);

            $response = $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertOk();
            $visit = collect($response->json('data.visits'))->firstWhere('sequence', 1);
            $rooms = collect($visit['rooms'])->keyBy('scheduleKey');

            expect($response->json('data.timezone'))->toBe('Asia/Damascus')
                ->and($visit['scheduledStartAt'])->toBe('2026-10-20T06:00:00+00:00')
                ->and($visit['scheduledEndAt'])->toBe('2026-10-20T11:00:00+00:00')
                ->and($rooms['schedule.gap.first']['startsAt'])->toBe('2026-10-20T06:00:00+00:00')
                ->and($rooms['schedule.gap.first']['endsAt'])->toBe('2026-10-20T06:45:00+00:00')
                ->and($rooms['schedule.gap.second']['endsAt'])->toBe('2026-10-20T11:00:00+00:00');
        }
    } finally {
        config(['app.timezone' => $originalAppTimezone]);
        date_default_timezone_set($originalPhpTimezone);
    }
});

it('leaves visit schedule null when room schedule evidence is missing malformed or reversed', function (string $case): void {
    $owner = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($owner, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    $schedules = DB::table('cleaning_room_schedules')->where('cleaning_booking_session_id', $session->id);

    match ($case) {
        'missing' => $schedules->delete(),
        'malformed' => $schedules->update(['starts_at' => '2026-02-30 06:00:00']),
        'reversed' => $schedules->update(['ends_at' => '2026-10-20 05:00:00']),
    };

    Sanctum::actingAs($owner);
    $response = $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertOk();
    $visit = collect($response->json('data.visits'))->firstWhere('sequence', 1);

    expect($visit['scheduledStartAt'])->toBeNull()
        ->and($visit['scheduledEndAt'])->toBeNull();

    if ($case === 'missing') {
        expect($visit['rooms'])->toBeEmpty();
    }

    if ($case === 'malformed') {
        expect($visit['rooms'][0]['startsAt'])->toBeNull();
    }
})->with(['missing', 'malformed', 'reversed']);

it('denies booking-wide V2 inventory material receipt while execution actions return 422', function (): void {
    $owner = scheduledSupervisor();
    $other = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($owner, $booking);
    CleaningBookingMaterialKit::query()->create([
        'cleaning_booking_id' => $booking->id, 'status' => CleaningBookingMaterialKit::READY,
    ]);
    $receive = "/api/v1/supervisor/bookings/{$booking->id}/materials/receive";

    Sanctum::actingAs($other);
    $this->postJson($receive)->assertNotFound();
    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/start")->assertUnprocessable()
        ->assertJsonPath('code', 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE');
    $this->postJson($receive)->assertNotFound();
    $this->assertDatabaseHas('cleaning_booking_material_kits', [
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::READY,
        'received_by_supervisor_user_id' => null,
    ]);
});

it('treats room-period visits as untracked attendance instead of fabricating team readiness', function (): void {
    $supervisor = scheduledSupervisor();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'property_details' => ['_supervisor_pricing' => ['fee' => 1000]],
    ]);
    foreach ([
        [1, 'bedroom.1', '2026-10-20', '09:00', 120, 120],
        [1, 'bathroom.1', '2026-10-20', '13:00', 60, 60],
        [2, 'bedroom.1', '2026-10-27', '09:00', 120, 120],
        [3, 'bedroom.1', '2026-11-03', '09:00', 120, 60],
        [4, 'bedroom.1', '2026-11-10', '29:90', 120, 120],
    ] as [$sequence, $key, $date, $time, $duration, $snapshotDuration]) {
        $booking->roomPeriods()->create([
            'client_key' => $key, 'visit_sequence' => $sequence,
            'room_type' => 'bedroom', 'room_size' => 'small', 'cleaning_mode' => 'regular',
            'scheduled_date' => $date, 'scheduled_time' => $time,
            'required_workers' => 2, 'estimated_duration_minutes' => $duration,
            'pricing_snapshot' => ['estimatedDurationMinutes' => $snapshotDuration],
        ]);
    }
    assignScheduledSupervisor($supervisor, $booking);
    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertOk()
        ->assertJsonCount(4, 'data.visits')
        ->assertJsonPath('data.visits.0.id', null)
        ->assertJsonPath('data.visits.0.attendance.source', 'unavailable')
        ->assertJsonPath('data.visits.0.attendance.teamReady', null)
        ->assertJsonPath('data.visits.0.scheduledStartAt', '2026-10-20T06:00:00+00:00')
        ->assertJsonPath('data.visits.0.scheduledEndAt', '2026-10-20T11:00:00+00:00')
        ->assertJsonPath('data.visits.1.rooms.0.roomKey', 'bedroom.1')
        ->assertJsonPath('data.visits.2.scheduledStartAt', null)
        ->assertJsonPath('data.visits.2.scheduledEndAt', null)
        ->assertJsonPath('data.visits.3.scheduledStartAt', null)
        ->assertJsonPath('data.visits.3.scheduledEndAt', null);
});

it('does not combine room-period and room-schedule interval sources for one visit', function (): void {
    $owner = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($owner, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    $booking->roomPeriods()->create([
        'cleaning_booking_session_id' => $session->id,
        'client_key' => 'bedroom.period.1', 'visit_sequence' => 1,
        'room_type' => 'bedroom', 'room_size' => 'small', 'cleaning_mode' => 'regular',
        'scheduled_date' => '2026-10-20', 'scheduled_time' => '09:00',
        'required_workers' => 2, 'estimated_duration_minutes' => 120,
        'pricing_snapshot' => ['estimatedDurationMinutes' => 120],
    ]);

    Sanctum::actingAs($owner);
    $response = $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertOk();
    $visit = collect($response->json('data.visits'))->firstWhere('sequence', 1);

    expect($visit['scheduledStartAt'])->toBeNull()
        ->and($visit['scheduledEndAt'])->toBeNull();
});

it('uses linked room-period intervals for a session-backed visit when no room schedules exist', function (): void {
    $owner = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($owner, $booking);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();
    DB::table('cleaning_room_schedules')->where('cleaning_booking_session_id', $session->id)->delete();
    $booking->roomPeriods()->create([
        'cleaning_booking_session_id' => $session->id,
        'client_key' => 'bedroom.period.1', 'visit_sequence' => 1,
        'room_type' => 'bedroom', 'room_size' => 'small', 'cleaning_mode' => 'regular',
        'scheduled_date' => '2026-10-20', 'scheduled_time' => '09:00',
        'required_workers' => 2, 'estimated_duration_minutes' => 90,
        'pricing_snapshot' => ['estimatedDurationMinutes' => 90],
    ]);

    Sanctum::actingAs($owner);
    $response = $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertOk();
    $visit = collect($response->json('data.visits'))->firstWhere('sequence', 1);

    expect($visit['scheduledStartAt'])->toBe('2026-10-20T06:00:00+00:00')
        ->and($visit['scheduledEndAt'])->toBe('2026-10-20T07:30:00+00:00')
        ->and($visit['rooms'][0]['roomKey'])->toBe('bedroom.period.1')
        ->and($visit['rooms'][0]['scheduledDate'])->toBe('2026-10-20')
        ->and($visit['rooms'][0]['scheduledTime'])->toBe('09:00');
});

it('fails closed when open-time visits contain room-schedule or room-period child evidence', function (string $childSource): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time', 'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned, 'property_details' => [],
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
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 20000,
        'active_slot' => 1,
    ]);

    if ($childSource === 'schedule') {
        $room = $booking->rooms()->create([
            'room_key' => 'open-time.child.1', 'room_type' => 'bedroom',
            'room_size' => 'small', 'display_label' => 'غرفة نوم', 'weight' => 1,
        ]);
        CleaningRoomSchedule::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'schedule_key' => 'open-time.schedule.1', 'visit_key' => 'open-time.visit.1',
            'timezone' => 'Asia/Damascus',
            'starts_at' => CarbonImmutable::parse($date.' 09:00', 'Asia/Damascus')->utc(),
            'ends_at' => CarbonImmutable::parse($date.' 10:00', 'Asia/Damascus')->utc(),
            'required_workers' => 2, 'work_minutes' => 60, 'duration_minutes' => 60,
        ]);
    } else {
        $booking->roomPeriods()->create([
            'cleaning_booking_session_id' => $session->id,
            'client_key' => 'open-time.period.1', 'visit_sequence' => 1,
            'room_type' => 'bedroom', 'room_size' => 'small', 'cleaning_mode' => 'regular',
            'scheduled_date' => $date, 'scheduled_time' => '09:00',
            'required_workers' => 2, 'estimated_duration_minutes' => 60,
            'pricing_snapshot' => ['estimatedDurationMinutes' => 60],
        ]);
    }

    Sanctum::actingAs($supervisor);
    $response = $this->getJson("/api/v1/supervisor/v2/bookings/{$booking->id}")->assertOk();

    expect($response->json('data.visits.0.scheduledStartAt'))->toBeNull()
        ->and($response->json('data.visits.0.scheduledEndAt'))->toBeNull();
})->with(['schedule', 'room-period']);

it('assigns eligible V2 visibility through ops with overlap checks across visits and legacy groups', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    Role::findOrCreate('admin', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $filamentAdmin = User::factory()->create(['is_active' => true]);
    $filamentAdmin->assignRole('admin');
    $supervisor = scheduledSupervisor();
    // Keep both the first visit and its week-later overlap in the future.
    // Fixed October 2026 dates expire and leave only one eligible visit.
    $firstVisitDate = now()->addDays(2)->toDateString();
    $overlappingVisitDate = now()->addDays(9)->toDateString();
    $v2 = scheduledAssignableV2Booking($firstVisitDate);
    $conflicting = scheduledAssignableV2Booking($overlappingVisitDate);
    $legacy = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team', 'number_of_workers' => 2,
        'scheduled_date' => $overlappingVisitDate, 'scheduled_time' => '09:30',
        'estimated_hours' => 4,
    ]);
    $standard = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $v2Url = fn (CleaningBooking $booking): string => "/api/v1/admin/cleaning/supervisor-v2-bookings/{$booking->id}/supervisor";
    Sanctum::actingAs($supervisor);
    $this->postJson($v2Url($v2), ['supervisorUserId' => $supervisor->id])->assertForbidden();
    Sanctum::actingAs($filamentAdmin);
    $this->postJson($v2Url($v2), ['supervisorUserId' => $supervisor->id])->assertForbidden();
    Sanctum::actingAs($ops);
    $this->postJson($v2Url($standard), ['supervisorUserId' => $supervisor->id])->assertNotFound();
    $this->postJson($v2Url($v2), ['supervisorUserId' => $supervisor->id])
        ->assertCreated()->assertJsonPath('data.unchanged', false);
    $this->postJson($v2Url($v2), ['supervisorUserId' => $supervisor->id])
        ->assertOk()->assertJsonPath('data.unchanged', true);
    $this->postJson($v2Url($conflicting), ['supervisorUserId' => $supervisor->id])->assertStatus(409);
    $this->postJson("/api/v1/admin/cleaning/supervised-bookings/{$legacy->id}/supervisor", [
        'supervisorUserId' => $supervisor->id,
    ])->assertStatus(409);
    $this->assertDatabaseCount('cleaning_supervisor_assignments', 0);
    $this->assertDatabaseCount('cleaning_booking_session_supervisor_assignments', 2);
});

it('refuses reassignment after materials move into supervisor custody or a session begins travel', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('Cleaning Ops Manager');
    $first = scheduledSupervisor();
    $second = scheduledSupervisor();
    $booking = scheduledV2Booking();
    assignScheduledSupervisor($first, $booking);
    $url = "/api/v1/admin/cleaning/supervisor-v2-bookings/{$booking->id}/supervisor";
    Sanctum::actingAs($ops);

    $kit = CleaningBookingMaterialKit::query()->create([
        'cleaning_booking_id' => $booking->id,
        'status' => CleaningBookingMaterialKit::RECEIVED,
        'received_at' => now(), 'received_by_supervisor_user_id' => $first->id,
    ]);
    $this->postJson($url, ['supervisorUserId' => $second->id, 'reason' => 'Supervisor scheduling conflict'])
        ->assertStatus(409);

    $kit->delete();
    $booking->sessions()->firstOrFail()->workerAssignments()->update(['started_travel_at' => now()]);
    $this->postJson($url, ['supervisorUserId' => $second->id, 'reason' => 'Supervisor scheduling conflict'])
        ->assertStatus(409);
    $this->assertDatabaseHas('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $first->id, 'active_slot' => 1,
    ]);
});

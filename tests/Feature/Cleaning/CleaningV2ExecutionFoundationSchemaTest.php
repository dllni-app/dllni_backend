<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;

function g2aSchemaSession(): array
{
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'is_pricing_final' => true,
        'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => 'g2a.visit.1'],
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'g2a-room',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'G2A room',
        'weight' => 1,
    ]);
    $schedule = CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'g2a.schedule.1',
        'visit_key' => 'g2a.visit.1',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);

    return [$booking, $session, $schedule];
}

it('creates the six empty G2A execution foundation tables without backfill', function (): void {
    $tables = [
        'cleaning_booking_session_tasks',
        'cleaning_booking_session_task_workers',
        'cleaning_booking_session_verifications',
        'cleaning_booking_session_supervisor_attendances',
        'cleaning_booking_session_supervisor_work_segments',
        'cleaning_booking_session_task_worker_segments',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and(DB::table($table)->count())->toBe(0);
    }
});

it('enforces raw task source XOR and active slot checks in SQLite', function (): void {
    [, $session, $schedule] = g2aSchemaSession();

    expect(fn () => DB::table('cleaning_booking_session_tasks')->insert([
        'cleaning_booking_session_id' => $session->id,
        'task_key' => 'invalid-no-source',
        'source_kind' => 'room_schedule',
        'required_workers' => 2,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('cleaning_booking_session_tasks')->insert([
        'cleaning_booking_session_id' => $session->id,
        'task_key' => 'invalid-kind',
        'source_kind' => 'anything_else',
        'required_workers' => 2,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('cleaning_booking_session_tasks')->insert([
        'cleaning_booking_session_id' => 999999999,
        'task_key' => 'invalid-foreign-key',
        'source_kind' => 'visit_scope',
        'required_workers' => 2,
    ]))->toThrow(QueryException::class);

    $taskId = DB::table('cleaning_booking_session_tasks')->insertGetId([
        'cleaning_booking_session_id' => $session->id,
        'task_key' => 'schedule:g2a.schedule.1',
        'source_kind' => 'room_schedule',
        'cleaning_room_schedule_id' => $schedule->id,
        'required_workers' => 2,
    ]);

    $secondRoom = $session->booking->rooms()->create([
        'room_key' => 'g2a-room-2',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'G2A room 2',
        'weight' => 1,
    ]);
    $secondSchedule = CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $session->cleaning_booking_id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $secondRoom->id,
        'schedule_key' => 'g2a.schedule.2',
        'visit_key' => 'g2a.visit.1',
        'timezone' => config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);
    expect(fn () => DB::table('cleaning_booking_session_tasks')->insert([
        'cleaning_booking_session_id' => $session->id,
        'task_key' => 'schedule:g2a.schedule.1',
        'source_kind' => 'room_schedule',
        'cleaning_room_schedule_id' => $secondSchedule->id,
        'required_workers' => 2,
    ]))->toThrow(QueryException::class);

    $worker = \App\Models\Worker::factory()->create();
    $workerAssignmentId = DB::table('cleaning_booking_session_worker_assignments')->insertGetId([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $actor = User::factory()->create();
    $supervisor = User::factory()->create();
    $supervisorAssignment = CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $actor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 20000,
        'active_slot' => 1,
    ]);

    expect(fn () => DB::table('cleaning_booking_session_task_workers')->insert([
        'cleaning_booking_session_task_id' => $taskId,
        'cleaning_booking_session_worker_assignment_id' => $workerAssignmentId,
        'membership_source' => 'explicit',
        'allocated_at' => now(),
        'active_slot' => 2,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('cleaning_booking_session_verifications')->insert([
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_session_supervisor_assignment_id' => $supervisorAssignment->id,
        'customer_user_id' => $session->booking->customer_id,
        'status' => 'pending',
        'expires_at' => now()->addMinutes(10),
        'active_slot' => 2,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('cleaning_booking_session_supervisor_work_segments')->insert([
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_session_supervisor_assignment_id' => $supervisorAssignment->id,
        'initiating_task_id' => $taskId,
        'started_at' => now(),
        'active_slot' => 2,
    ]))->toThrow(QueryException::class);

    $taskWorkerId = DB::table('cleaning_booking_session_task_workers')->insertGetId([
        'cleaning_booking_session_task_id' => $taskId,
        'cleaning_booking_session_worker_assignment_id' => $workerAssignmentId,
        'membership_source' => 'explicit',
        'allocated_at' => now(),
        'active_slot' => 1,
    ]);
    expect(fn () => DB::table('cleaning_booking_session_task_workers')->insert([
        'cleaning_booking_session_task_id' => $taskId,
        'cleaning_booking_session_worker_assignment_id' => $workerAssignmentId,
        'membership_source' => 'explicit',
        'allocated_at' => now(),
        'active_slot' => 1,
    ]))->toThrow(QueryException::class);
    DB::table('cleaning_booking_session_task_workers')->insert([
        'cleaning_booking_session_task_id' => $taskId,
        'cleaning_booking_session_worker_assignment_id' => $workerAssignmentId,
        'membership_source' => 'history',
        'allocated_at' => now()->subMinute(),
        'released_at' => now(),
        'active_slot' => null,
    ]);
    DB::table('cleaning_booking_session_task_workers')->insert([
        'cleaning_booking_session_task_id' => $taskId,
        'cleaning_booking_session_worker_assignment_id' => $workerAssignmentId,
        'membership_source' => 'history',
        'allocated_at' => now()->subMinutes(2),
        'released_at' => now()->subMinute(),
        'active_slot' => null,
    ]);
    expect(DB::table('cleaning_booking_session_task_workers')->count())->toBe(3);

    expect(fn () => DB::table('cleaning_booking_session_task_worker_segments')->insert([
        'cleaning_booking_session_task_worker_id' => $taskWorkerId,
        'started_at' => now(),
        'active_slot' => 2,
    ]))->toThrow(QueryException::class);
});

<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningSupervisorEarnedCompensationService;

function supervisorEarnedFixture(): array
{
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'open_time', 'number_of_workers' => 2,
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1, 'session_type' => 'open_time',
        'scheduled_date' => now()->toDateString(), 'scheduled_time' => '09:00',
        'duration_hours' => 3, 'required_workers' => 2,
        'status' => 'worker_assigned',
    ]);
    $supervisor = User::factory()->create();
    $assignment = $session->supervisorAssignments()->create([
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now()->subHours(2),
        'compensation_hourly_rate' => '120.00', 'active_slot' => 1,
    ]);
    $task = $session->executionTasks()->create([
        'task_key' => 'visit_scope', 'source_kind' => 'visit_scope',
        'required_workers' => 2, 'status' => 'allocated',
    ]);

    return [$session, $assignment, $task, $supervisor];
}

it('calculates earned supervisor payment from actual closed work minutes, not scheduled duration', function (): void {
    [$session, $assignment, $task, $supervisor] = supervisorEarnedFixture();
    $start = now()->startOfMinute()->subMinutes(60);
    $assignment->workSegments()->create([
        'cleaning_booking_session_id' => $session->id,
        'initiating_task_id' => $task->id,
        'started_at' => $start, 'ended_at' => $start->copy()->addMinutes(60),
        'end_reason' => 'all_tasks_finished', 'ended_by_user_id' => $supervisor->id,
        'active_slot' => null,
    ]);
    $result = app(CleaningSupervisorEarnedCompensationService::class)->assess($assignment);
    expect($result)->toMatchArray([
        'status' => 'calculated', 'minutes' => 60,
        'hourlyRate' => '120.00', 'earnedAmount' => '120.00', 'reasonCodes' => [],
    ]);
});

it('rejects open supervisor segments and excludes duplicate overlapping worked time', function (): void {
    [$session, $assignment, $task, $supervisor] = supervisorEarnedFixture();
    $start = now()->startOfMinute()->subMinutes(60);
    foreach ([[0, 40], [20, 60]] as [$from, $to]) {
        $assignment->workSegments()->create([
            'cleaning_booking_session_id' => $session->id,
            'initiating_task_id' => $task->id,
            'started_at' => $start->copy()->addMinutes($from),
            'ended_at' => $start->copy()->addMinutes($to),
            'end_reason' => 'replaced_by_next', 'ended_by_user_id' => $supervisor->id,
            'active_slot' => null,
        ]);
    }
    $result = app(CleaningSupervisorEarnedCompensationService::class)->assess($assignment);
    expect($result['status'])->toBe('calculated')
        ->and($result['minutes'])->toBe(60)
        ->and($result['earnedAmount'])->toBe('120.00');
    $assignment->workSegments()->create([
        'cleaning_booking_session_id' => $session->id,
        'initiating_task_id' => $task->id,
        'started_at' => now(), 'active_slot' => 1,
    ]);
    $blocked = app(CleaningSupervisorEarnedCompensationService::class)->assess($assignment);
    expect($blocked['status'])->toBe('blocked')
        ->and($blocked['earnedAmount'])->toBeNull()
        ->and($blocked['reasonCodes'])->toContain('supervisor_work_segment_unclosed');
});

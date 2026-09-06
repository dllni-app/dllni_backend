<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

use function Pest\Laravel\postJson;

/** @return array{0:User,1:Worker,2:CleaningBooking,3:CleaningBookingSession,4:CleaningBookingSessionWorkerAssignment} */
function makeRecurringAttendanceVisit(int $minutesPastStart): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $scheduledAt = now(config('app.timezone'))->subMinutes($minutesPastStart);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'number_of_workers' => 1,
        'base_price' => 1000,
        'admin_margin_amount' => 100,
        'total_price' => 1100,
    ]);
    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered,
        'status' => CleaningBookingSessionStatus::WorkerAssigned,
        'base_price' => 1000,
        'admin_margin_amount' => 100,
        'total_price' => 1100,
    ]);
    $assignment = CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted,
        'accepted_at' => now()->subHour(),
        'service_share_amount' => 1000,
        'admin_margin_amount' => 100,
        'worker_amount' => 900,
        'currency' => 'SYP',
    ]);

    return [$customer, $worker, $booking, $session, $assignment];
}

it('allows a customer to report lateness after the late grace period and resolves it when travel starts', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session, $assignment] = makeRecurringAttendanceVisit(20);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'wait',
        'note' => 'سأنتظر قليلاً',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canReportLate', false)
        ->assertJsonPath('data.schedule.sessions.0.canReportNoTravel', false)
        ->assertJsonPath('data.schedule.sessions.0.attendance.incidents.0.action', 'wait');

    $assignment->refresh();
    expect($assignment->late_reported_at)->not->toBeNull()
        ->and($assignment->attendance_action)->toBe('wait')
        ->and($assignment->attendance_resolved_at)->toBeNull();

    Sanctum::actingAs($worker->user);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/start-travel")
        ->assertOk();

    expect($assignment->fresh()->attendance_resolved_at)->not->toBeNull();
});

it('reopens recurring coverage when the customer requests replacement after no travel', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session, $assignment] = makeRecurringAttendanceVisit(40);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'replace',
        'note' => 'العامل لم يبدأ التنقل',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.0.coverageStatus', CleaningBookingSessionCoverageStatus::Searching->value)
        ->assertJsonPath('data.schedule.sessions.0.attendance.incidents.0.action', 'replace');

    $assignment->refresh();
    expect($assignment->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($assignment->no_travel_reported_at)->not->toBeNull()
        ->and($assignment->attendance_resolved_at)->not->toBeNull();
});

it('lets the customer cancel a recurring visit without a customer fee after confirmed no travel', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session, $assignment] = makeRecurringAttendanceVisit(40);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'cancel',
        'note' => 'لا أريد انتظار بديل',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Cancelled->value)
        ->assertJsonPath('data.schedule.sessions.0.pricing.cancellationFee', 0);

    $session->refresh();
    expect($session->status)->toBe(CleaningBookingSessionStatus::Cancelled)
        ->and((float) $session->cancellation_fee)->toBe(0.0)
        ->and($assignment->fresh()->no_travel_reported_at)->not->toBeNull();
});

it('rejects attendance escalation before its configured grace period', function (): void {
    config()->set('cleaning_attendance.late_grace_minutes', 15);
    config()->set('cleaning_attendance.no_travel_grace_minutes', 30);
    [$customer, $worker, $booking, $session] = makeRecurringAttendanceVisit(5);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/attendance", [
        'workerIds' => [$worker->id],
        'action' => 'wait',
    ])->assertUnprocessable();
});

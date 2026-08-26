<?php

declare(strict_types=1);

use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingMultiDayTeamService;
use Modules\Cleaning\Services\WorkerBookingScheduleConflictService;

it('blocks a worker when any event session overlaps an existing accepted booking', function (): void {
    $worker = Worker::factory()->create();
    $date = now()->addDays(5)->toDateString();

    $busy = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => $date,
        'scheduled_time' => '17:00',
        'number_of_workers' => 1,
    ]);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $busy->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $busy->id,
        'sequence' => 1,
        'scheduled_date' => $date,
        'scheduled_time' => '17:00',
        'duration_hours' => 4,
        'status' => CleaningBookingSessionStatus::WorkerAssigned->value,
    ]);

    $candidate = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::Pending->value,
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '10:00',
        'number_of_workers' => 1,
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $candidate->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Scheduled->value,
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $candidate->id,
        'sequence' => 2,
        'scheduled_date' => $date,
        'scheduled_time' => '18:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Scheduled->value,
    ]);

    $conflicts = app(WorkerBookingScheduleConflictService::class)->conflictsForBooking($worker, $candidate->fresh(['sessions']));

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['date'])->toBe($date)
        ->and($conflicts[0]['conflictingBookingId'])->toBe($busy->id);
});

it('keeps completed session earnings when a worker is released from future event days', function (): void {
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::PartiallyCompleted->value,
        'number_of_workers' => 1,
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => '10:00',
        'base_price' => 2000,
        'total_price' => 2000,
    ]);
    $parent = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now()->subDays(2),
        'service_share_amount' => 2000,
        'worker_amount' => 2000,
    ]);
    $completed = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Completed->value,
        'base_price' => 1000,
        'total_price' => 1000,
        'is_pricing_final' => true,
        'work_finished_at' => now()->subHours(2),
    ]);
    $future = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 2,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::WorkerAssigned->value,
        'base_price' => 1000,
        'total_price' => 1000,
        'is_pricing_final' => true,
    ]);
    CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $completed->id,
        'cleaning_booking_worker_assignment_id' => $parent->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'service_share_amount' => 1000,
        'worker_amount' => 900,
        'admin_margin_amount' => 100,
    ]);
    CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $future->id,
        'cleaning_booking_worker_assignment_id' => $parent->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'service_share_amount' => 1000,
        'worker_amount' => 900,
        'admin_margin_amount' => 100,
    ]);

    $updated = app(CleaningBookingMultiDayTeamService::class)->releaseWorker($booking, $worker, 'Admin replacement');

    $parent->refresh();
    $completedRow = CleaningBookingSessionWorkerAssignment::query()
        ->where('cleaning_booking_session_id', $completed->id)
        ->where('worker_id', $worker->id)
        ->firstOrFail();
    $futureRow = CleaningBookingSessionWorkerAssignment::query()
        ->where('cleaning_booking_session_id', $future->id)
        ->where('worker_id', $worker->id)
        ->firstOrFail();

    expect($completedRow->status)->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and((float) $completedRow->worker_amount)->toBe(900.0)
        ->and($futureRow->status)->toBe(CleaningBookingWorkerAssignmentStatus::Rejected)
        ->and($parent->status)->toBe(CleaningBookingWorkerAssignmentStatus::Rejected)
        ->and((float) $parent->worker_amount)->toBe(900.0)
        ->and($updated->status)->toBe(CleaningBookingStatus::PartiallyCompleted)
        ->and($updated->acceptedWorkerCount())->toBe(0);
});

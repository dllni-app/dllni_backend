<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSessionAcceptanceService;
use Modules\Cleaning\Services\CleaningV3DistinctVisitCrewPolicy;

function v3SequentialBooking(): array
{
    $user = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $booking->forceFill(['contract_version' => 'v3'])->save();

    $first = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => 'event_assistance',
        'calculation_mode' => 'hourly',
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 8,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => CleaningBookingSessionStatus::WorkerAssigned,
        'appointment_key' => 'event-12h',
        'occurrence_key' => 'event-first',
    ]);
    $second = $booking->sessions()->create([
        'sequence' => 2,
        'session_type' => 'event_assistance',
        'calculation_mode' => 'hourly',
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '17:00',
        'duration_hours' => 4,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => CleaningBookingSessionStatus::WorkerAssigned,
        'appointment_key' => 'event-12h',
        'occurrence_key' => 'event-second',
    ]);

    return [$booking, $first, $second];
}

test('the same worker cannot occupy both visits of one V3 twelve-hour event even if times do not overlap', function (): void {
    [$booking, $first, $second] = v3SequentialBooking();
    $worker = Worker::factory()->create();
    $first->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now(),
    ]);

    $policy = app(CleaningV3DistinctVisitCrewPolicy::class);
    expect($policy->isSequentialSibling($booking, $second, $first))->toBeTrue()
        ->and($policy->hasCommittedSibling($booking, $second, $worker))->toBeTrue();

    // The actual worker admission path must not fall through to V2 pricing
    // when this V3-specific crew requirement has already failed.
    $result = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $worker, [(int) $second->id]);
    expect($result['acceptedSessionIds'])->toBe([])
        ->and($result['rejected'][0]['reasonCode'])->toBe('v3_distinct_crew_required');
});

test('releasing a worker from the first event visit permits a new independent assignment', function (): void {
    [$booking, $first, $second] = v3SequentialBooking();
    $worker = Worker::factory()->create();
    $assignment = $first->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now(),
    ]);

    $policy = app(CleaningV3DistinctVisitCrewPolicy::class);
    expect($policy->hasCommittedSibling($booking, $second, $worker))->toBeTrue();

    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Withdrawn,
        'released_at' => now(),
    ])->save();
    expect($policy->hasCommittedSibling($booking, $second, $worker))->toBeFalse();

    // A different appointment is an independent crew contract; ordinary
    // recurring bookings can reuse workers on separate days.
    $second->forceFill(['appointment_key' => 'other-appointment'])->save();
    expect($policy->isSequentialSibling($booking, $second, $first))->toBeFalse();

    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted,
        'released_at' => null,
    ])->save();
    expect($policy->hasCommittedSibling($booking, $second, $worker))->toBeFalse();

    $booking->forceFill(['contract_version' => 'v2'])->save();
    $second->forceFill(['appointment_key' => 'event-12h'])->save();
    expect($policy->hasCommittedSibling($booking, $second, $worker))->toBeFalse();
});

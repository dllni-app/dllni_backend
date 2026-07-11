<?php

declare(strict_types=1);

use App\Jobs\ConvertPreferredCleaningBookingToOpenJob;
use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningBookingActionNotificationService;

beforeEach(function (): void {
    Bus::fake([
        NotifyEligibleWorkersNewOrderJob::class,
        ConvertPreferredCleaningBookingToOpenJob::class,
    ]);
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('repeats customer security-code reminders and stops after work starts', function (): void {
    $now = Carbon::parse('2026-07-12 15:02:00', config('app.timezone'));
    Carbon::setTestNow($now);
    [$booking, $assignment, $worker] = repeatedReminderBooking(
        $now,
        CleaningBookingStatus::AwaitingStartVerification,
        CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
        [
            'started_travel_at' => $now->copy()->subMinutes(20),
            'arrived_at' => $now->copy()->subMinutes(2),
        ],
    );

    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'code' => hash('sha256', '1234'),
        'code_hash' => hash('sha256', '1234'),
        'attempts' => 0,
        'expires_at' => $now->copy()->addMinutes(8),
        'consumed_at' => null,
        'last_attempt_at' => null,
        'created_at' => $now->copy()->subMinutes(2),
        'updated_at' => $now->copy()->subMinutes(2),
    ]);

    $service = app(CleaningBookingActionNotificationService::class);
    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(2)))->toBe(1);

    $startedAt = $now->copy()->addMinutes(3);
    $booking->forceFill([
        'status' => CleaningBookingStatus::InProgress,
        'customer_confirmed_at' => $startedAt,
        'work_started_at' => $startedAt,
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'start_approved_at' => $startedAt,
        'work_started_at' => $startedAt,
    ])->save();

    expect($service->dispatchDue($now->copy()->addMinutes(4)))->toBe(0)
        ->and(repeatedDispatchCount('cleaning.booking.customer_verification_reminder'))->toBe(2);
});

it('repeats worker travel warnings and stops after travel starts', function (): void {
    $now = Carbon::parse('2026-07-12 14:50:00', config('app.timezone'));
    Carbon::setTestNow($now);
    [$booking, $assignment] = repeatedReminderBooking(
        $now,
        CleaningBookingStatus::WorkerAssigned,
        CleaningBookingWorkerAssignmentStatus::Accepted,
    );

    $service = app(CleaningBookingActionNotificationService::class);
    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(5)))->toBe(1);

    $startedAt = $now->copy()->addMinutes(6);
    $booking->forceFill(['started_travel_at' => $startedAt])->save();
    $assignment->forceFill(['started_travel_at' => $startedAt])->save();
    $before = repeatedDispatchCount('cleaning.booking.worker_start_travel_warning');

    $service->dispatchDue($now->copy()->addMinutes(10));
    expect(repeatedDispatchCount('cleaning.booking.worker_start_travel_warning'))->toBe($before);
});

it('repeats late-arrival warnings and stops after arrival', function (): void {
    $now = Carbon::parse('2026-07-12 15:05:00', config('app.timezone'));
    Carbon::setTestNow($now);
    [$booking, $assignment] = repeatedReminderBooking(
        $now,
        CleaningBookingStatus::WorkerAssigned,
        CleaningBookingWorkerAssignmentStatus::Accepted,
        ['started_travel_at' => $now->copy()->subMinutes(20)],
    );

    $service = app(CleaningBookingActionNotificationService::class);
    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(5)))->toBe(1);

    $arrivedAt = $now->copy()->addMinutes(6);
    $booking->forceFill([
        'status' => CleaningBookingStatus::InProgress,
        'arrived_at' => $arrivedAt,
        'work_started_at' => $arrivedAt,
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'arrived_at' => $arrivedAt,
        'start_approved_at' => $arrivedAt,
        'work_started_at' => $arrivedAt,
    ])->save();

    expect($service->dispatchDue($now->copy()->addMinutes(10)))->toBe(0)
        ->and(repeatedDispatchCount('cleaning.booking.worker_arrival_critical_warning'))->toBe(2);
});

it('reminds a verified assignment repeatedly until that worker starts', function (): void {
    $now = Carbon::parse('2026-07-12 15:02:00', config('app.timezone'));
    Carbon::setTestNow($now);
    $verifiedAt = $now->copy()->subMinutes(2);
    [$booking, $assignment] = repeatedReminderBooking(
        $now,
        CleaningBookingStatus::AwaitingStartVerification,
        CleaningBookingWorkerAssignmentStatus::StartApproved,
        [
            'started_travel_at' => $now->copy()->subMinutes(20),
            'arrived_at' => $now->copy()->subMinutes(5),
            'customer_confirmed_at' => $verifiedAt,
            'start_approved_at' => $verifiedAt,
        ],
    );

    $service = app(CleaningBookingActionNotificationService::class);
    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(3)))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(8)))->toBe(1);

    $startedAt = $now->copy()->addMinutes(9);
    $booking->forceFill([
        'status' => CleaningBookingStatus::InProgress,
        'work_started_at' => $startedAt,
    ])->save();
    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'work_started_at' => $startedAt,
    ])->save();

    expect($service->dispatchDue($now->copy()->addMinutes(13)))->toBe(0)
        ->and(CleaningNotificationDispatch::query()
            ->whereIn('canonical_type', [
                'cleaning.booking.worker_start_confirmation_reminder',
                'cleaning.booking.worker_start_confirmation_warning',
            ])->count())->toBe(3);
});

it('repeats pending completion and extension decisions until they are resolved', function (): void {
    $now = Carbon::parse('2026-07-12 17:05:00', config('app.timezone'));
    Carbon::setTestNow($now);
    [$completion, $completionAssignment] = repeatedReminderBooking(
        $now,
        CleaningBookingStatus::AwaitingCustomerCompletion,
        CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
        [
            'work_started_at' => $now->copy()->subHours(2),
            'work_finished_at' => $now->copy()->subMinutes(5),
        ],
    );

    $service = app(CleaningBookingActionNotificationService::class);
    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(5)))->toBe(1);

    $completion->forceFill(['status' => CleaningBookingStatus::Completed])->save();
    $completionAssignment->forceFill(['status' => CleaningBookingWorkerAssignmentStatus::Completed])->save();
    expect($service->dispatchDue($now->copy()->addMinutes(10)))->toBe(0)
        ->and(repeatedDispatchCount('cleaning.booking.customer_completion_action_reminder'))->toBe(2);

    [$extension, $extensionAssignment, $worker] = repeatedReminderBooking(
        $now,
        CleaningBookingStatus::TimeExtensionRequested,
        CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
        ['work_started_at' => $now->copy()->subHours(2)],
    );
    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $extension->id,
        'booking_type' => $extension->getMorphClass(),
        'worker_id' => $worker->id,
        'customer_response' => 'extend_time',
        'worker_response' => null,
        'sent_at' => $now->copy()->subMinutes(5),
        'customer_responded_at' => $now->copy()->subMinutes(5),
        'worker_responded_at' => null,
        'additional_minutes' => 30,
        'quoted_amount' => 10,
        'quoted_currency' => 'USD',
    ]);

    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now->copy()->addMinutes(5)))->toBe(1);

    $warning->forceFill(['worker_response' => 'extend_time', 'worker_responded_at' => $now->copy()->addMinutes(6)])->save();
    $extension->forceFill(['status' => CleaningBookingStatus::InProgress])->save();
    $extensionAssignment->forceFill(['status' => CleaningBookingWorkerAssignmentStatus::InProgress])->save();

    expect($service->dispatchDue($now->copy()->addMinutes(10)))->toBe(0)
        ->and(repeatedDispatchCount('cleaning.booking.worker_extension_response_reminder'))->toBe(2);
});

/** @return array{0:CleaningBooking,1:\Modules\Cleaning\Models\CleaningBookingWorkerAssignment,2:Worker} */
function repeatedReminderBooking(
    Carbon $now,
    CleaningBookingStatus $bookingStatus,
    CleaningBookingWorkerAssignmentStatus $assignmentStatus,
    array $overrides = [],
): array {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => $bookingStatus->value,
        'scheduled_date' => '2026-07-12',
        'scheduled_time' => '15:00',
        'started_travel_at' => $overrides['started_travel_at'] ?? null,
        'arrived_at' => $overrides['arrived_at'] ?? null,
        'customer_confirmed_at' => $overrides['customer_confirmed_at'] ?? null,
        'work_started_at' => $overrides['work_started_at'] ?? null,
        'work_finished_at' => $overrides['work_finished_at'] ?? null,
    ]);
    $assignment = $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => $assignmentStatus->value,
        'accepted_at' => $now->copy()->subHour(),
        'started_travel_at' => $overrides['started_travel_at'] ?? null,
        'arrived_at' => $overrides['arrived_at'] ?? null,
        'start_approved_at' => $overrides['start_approved_at'] ?? null,
        'work_started_at' => $overrides['work_started_at'] ?? null,
        'work_finished_at' => $overrides['work_finished_at'] ?? null,
    ]);

    return [$booking, $assignment, $worker];
}

function repeatedDispatchCount(string $canonicalType): int
{
    return CleaningNotificationDispatch::query()->where('canonical_type', $canonicalType)->count();
}

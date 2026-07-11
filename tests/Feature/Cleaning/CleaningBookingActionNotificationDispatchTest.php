<?php

declare(strict_types=1);

use App\Jobs\ConvertPreferredCleaningBookingToOpenJob;
use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Modules\Cleaning\Services\CleaningBookingActionNotificationService;

beforeEach(function (): void {
    Bus::fake([
        NotifyEligibleWorkersNewOrderJob::class,
        ConvertPreferredCleaningBookingToOpenJob::class,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends the worker travel reminder once for an assigned booking', function (): void {
    $now = Carbon::parse('2026-07-12 14:30:00', config('app.timezone'));
    Carbon::setTestNow($now);

    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => '2026-07-12',
        'scheduled_time' => '15:00',
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => $now->copy()->subHour(),
    ]);

    Notification::fake();

    $service = app(CleaningBookingActionNotificationService::class);

    expect($service->dispatchDue($now))->toBe(1)
        ->and($service->dispatchDue($now))->toBe(0)
        ->and(CleaningNotificationDispatch::query()->count())->toBe(1)
        ->and(CleaningNotificationDispatch::query()->value('status'))->toBe('sent');

    Notification::assertSentTo(
        $workerUser,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification): bool {
            $property = new ReflectionProperty($notification, 'canonicalType');

            return $property->getValue($notification) === 'cleaning.booking.worker_start_travel_reminder';
        },
    );
});

it('warns only the worker assignment that has not started travelling', function (): void {
    $now = Carbon::parse('2026-07-12 14:50:00', config('app.timezone'));
    Carbon::setTestNow($now);

    $customer = User::factory()->create();
    $startedUser = User::factory()->create();
    $missingUser = User::factory()->create();
    $startedWorker = Worker::factory()->create(['user_id' => $startedUser->id]);
    $missingWorker = Worker::factory()->create(['user_id' => $missingUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $startedWorker->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => '2026-07-12',
        'scheduled_time' => '15:00',
    ]);
    $booking->workerAssignments()->createMany([
        [
            'worker_id' => $startedWorker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
            'accepted_at' => $now->copy()->subHour(),
            'started_travel_at' => $now->copy()->subMinutes(5),
        ],
        [
            'worker_id' => $missingWorker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
            'accepted_at' => $now->copy()->subHour(),
        ],
    ]);

    Notification::fake();

    expect(app(CleaningBookingActionNotificationService::class)->dispatchDue($now))->toBe(1);

    Notification::assertNotSentTo($startedUser, BookingLifecycleNotification::class);
    Notification::assertSentTo(
        $missingUser,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification): bool {
            $property = new ReflectionProperty($notification, 'canonicalType');

            return $property->getValue($notification) === 'cleaning.booking.worker_start_travel_warning';
        },
    );
});

it('reminds the customer to verify an arrived worker', function (): void {
    $now = Carbon::parse('2026-07-12 15:10:00', config('app.timezone'));
    Carbon::setTestNow($now);

    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::AwaitingStartVerification->value,
        'scheduled_date' => '2026-07-12',
        'scheduled_time' => '15:00',
        'started_travel_at' => $now->copy()->subMinutes(20),
        'arrived_at' => $now->copy()->subMinutes(5),
        'customer_confirmed_at' => null,
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
        'accepted_at' => $now->copy()->subHour(),
        'started_travel_at' => $now->copy()->subMinutes(20),
        'arrived_at' => $now->copy()->subMinutes(5),
    ]);

    Notification::fake();

    expect(app(CleaningBookingActionNotificationService::class)->dispatchDue($now))->toBe(1);

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification): bool {
            $property = new ReflectionProperty($notification, 'canonicalType');

            return $property->getValue($notification) === 'cleaning.booking.customer_verification_reminder';
        },
    );
});

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
use Modules\Cleaning\Models\CleaningBooking;
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

it('reminds a legacy assigned worker when no assignment row exists', function (): void {
    $now = Carbon::parse('2026-07-12 14:30:00', config('app.timezone'));
    Carbon::setTestNow($now);

    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => '2026-07-12',
        'scheduled_time' => '15:00',
        'started_travel_at' => null,
    ]);

    Notification::fake();

    expect(app(CleaningBookingActionNotificationService::class)->dispatchDue($now))->toBe(1);

    Notification::assertSentTo(
        $workerUser,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification): bool {
            $property = new ReflectionProperty($notification, 'canonicalType');

            return $property->getValue($notification) === 'cleaning.booking.worker_start_travel_reminder';
        },
    );
});

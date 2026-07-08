<?php

declare(strict_types=1);

use App\Jobs\ConvertPreferredCleaningBookingToOpenJob;
use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('sends created lifecycle notifications with a customer target role when a cleaning order is created', function (): void {
    Bus::fake([
        NotifyEligibleWorkersNewOrderJob::class,
        ConvertPreferredCleaningBookingToOpenJob::class,
    ]);
    Notification::fake();

    $customer = User::factory()->create();

    CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
    ]);

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        fn (BookingLifecycleNotification $notification): bool => notificationPrivateProperty($notification, 'targetRole') === 'customer'
    );
});

it('sends updated lifecycle notifications with separate customer and worker target roles', function (): void {
    Bus::fake([
        NotifyEligibleWorkersNewOrderJob::class,
        ConvertPreferredCleaningBookingToOpenJob::class,
    ]);
    Notification::fake();

    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'gender_preference' => 'any',
        'base_price' => 100,
        'total_price' => 120,
    ]);

    Notification::fake();

    $booking->update([
        'base_price' => 110,
        'total_price' => 130,
    ]);

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        fn (BookingLifecycleNotification $notification): bool => notificationPrivateProperty($notification, 'targetRole') === 'customer'
    );

    Notification::assertSentTo(
        $workerUser,
        BookingLifecycleNotification::class,
        fn (BookingLifecycleNotification $notification): bool => notificationPrivateProperty($notification, 'targetRole') === 'worker'
    );
});

function notificationPrivateProperty(BookingLifecycleNotification $notification, string $property): mixed
{
    $reflectionProperty = new ReflectionProperty($notification, $property);

    return $reflectionProperty->getValue($notification);
}

<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

it('notifies both customer and worker when worker rejects an extension request', function (): void {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $customer = User::factory()->create(['email' => 'customer-extension-result@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-extension-result@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_id' => $worker->id,
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 30,
    ]);

    Notification::fake();
    Sanctum::actingAs($workerUser);

    $message = 'I cannot continue for the requested extra time.';
    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => $message,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.workerRejectMessage', $message);

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($message): bool {
            $extraData = timeWarningNotificationPrivateProperty($notification, 'extraData');

            return timeWarningNotificationPrivateProperty($notification, 'canonicalType')
                    === 'cleaning.booking.time_extension_rejected'
                && timeWarningNotificationPrivateProperty($notification, 'targetRole') === 'customer'
                && ($extraData['message'] ?? null) === $message
                && ($extraData['workerRejectMessage'] ?? null) === $message
                && ($extraData['worker_reject_message'] ?? null) === $message;
        }
    );

    Notification::assertSentTo(
        $workerUser,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($message): bool {
            $extraData = timeWarningNotificationPrivateProperty($notification, 'extraData');

            return timeWarningNotificationPrivateProperty($notification, 'canonicalType')
                    === 'cleaning.booking.time_extension_rejected'
                && timeWarningNotificationPrivateProperty($notification, 'targetRole') === 'worker'
                && ($extraData['message'] ?? null) === $message
                && ($extraData['workerRejectMessage'] ?? null) === $message
                && ($extraData['worker_reject_message'] ?? null) === $message;
        }
    );
});

function timeWarningNotificationPrivateProperty(
    BookingLifecycleNotification $notification,
    string $property,
): mixed {
    return (new ReflectionProperty($notification, $property))->getValue($notification);
}

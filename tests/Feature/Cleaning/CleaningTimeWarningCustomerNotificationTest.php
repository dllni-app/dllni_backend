<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Models\EventBooking;

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

    $warning->refresh();
    expect($warning->worker_response)->toBe(CleaningTimeWarningResponse::CommitCurrentTime)
        ->and($warning->worker_responded_at)->not->toBeNull()
        ->and($warning->worker_reject_message)->toBe($message);

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

it('notifies the customer when worker rejects an event assistance extension request', function (): void {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $customer = User::factory()->create(['email' => 'customer-event-assistance-extension@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-event-assistance-extension@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'property_type' => 'event_assistance',
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

    $message = 'I cannot stay for the event extension.';
    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => $message,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.workerRejectMessage', $message)
        ->assertJsonPath('data.bookingType', 'cleaning_booking');

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($customer, $message): bool {
            $payload = $notification->toArray($customer);
            $extraData = $payload['data'] ?? [];

            return ($payload['canonicalType'] ?? null) === 'cleaning.booking.time_extension_rejected'
                && ($payload['targetRole'] ?? null) === 'customer'
                && ($extraData['workerRejectMessage'] ?? null) === $message
                && ($extraData['bookingType'] ?? null) === 'cleaning_booking'
                && ($extraData['propertyType'] ?? null) === 'event_assistance';
        }
    );
});

it('notifies legacy event booking customer when worker rejects extension request without deep link', function (): void {
    $customer = User::factory()->create(['email' => 'customer-legacy-event-extension@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-legacy-event-extension@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    $booking = EventBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => EventBookingStatus::InProgress,
    ]);

    $warning = CleaningTimeWarning::create([
        'booking_id' => $booking->id,
        'booking_type' => 'event_booking',
        'worker_id' => $worker->id,
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 30,
    ]);

    Notification::fake();
    Sanctum::actingAs($workerUser);

    $message = 'The event cannot be extended.';
    $response = $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => $message,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.bookingType', 'event_booking')
        ->assertJsonPath('data.bookingStatus', EventBookingStatus::Completed->value)
        ->assertJsonPath('data.workerRejectMessage', $message);

    $warning->refresh();
    expect($warning->worker_response)->toBe(CleaningTimeWarningResponse::CommitCurrentTime)
        ->and($warning->worker_responded_at)->not->toBeNull()
        ->and($warning->worker_reject_message)->toBe($message)
        ->and($booking->fresh()->status)->toBe(EventBookingStatus::Completed);

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($customer, $message): bool {
            $payload = $notification->toArray($customer);
            $extraData = $payload['data'] ?? [];

            return ($payload['canonicalType'] ?? null) === 'cleaning.booking.time_extension_rejected'
                && ($payload['targetRole'] ?? null) === 'customer'
                && ($extraData['workerRejectMessage'] ?? null) === $message
                && ($extraData['bookingType'] ?? null) === 'event_booking'
                && ! array_key_exists('deep_link_target', $extraData)
                && ! array_key_exists('deepLinkTarget', $extraData)
                && ! array_key_exists('args', $extraData);
        }
    );
});

function timeWarningNotificationPrivateProperty(
    BookingLifecycleNotification $notification,
    string $property,
): mixed {
    return (new ReflectionProperty($notification, $property))->getValue($notification);
}

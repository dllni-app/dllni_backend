<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('notifies an accepted linked worker when customer cancels a pending multi-worker order', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    $rejectedWorkerUser = User::factory()->create();
    $rejectedWorker = Worker::factory()->create(['user_id' => $rejectedWorkerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'number_of_workers' => 2,
    ]);

    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 900,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'worker_amount' => 800,
        'currency' => 'SYP',
    ]);

    $booking->workerAssignments()->create([
        'worker_id' => $rejectedWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Rejected->value,
        'accepted_at' => null,
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    Notification::fake();
    Sanctum::actingAs($customer);

    $reason = 'Customer no longer needs the service.';

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/cancel", [
        'reason' => $reason,
    ])->assertOk();

    Notification::assertSentTo(
        $workerUser,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($workerUser, $booking, $reason): bool {
            $payload = $notification->toArray($workerUser);
            $data = $payload['data'] ?? [];

            return ($payload['canonicalType'] ?? null) === 'cleaning.booking.order_cancelled'
                && ($payload['targetRole'] ?? null) === 'worker'
                && ($payload['title'] ?? null) === 'تم إلغاء الطلب'
                && ($payload['body'] ?? null) === "تم إلغاء حجز التنظيف رقم {$booking->booking_number}."
                && ($data['bookingId'] ?? null) === $booking->id
                && ($data['orderId'] ?? null) === $booking->id
                && ($data['deep_link_target'] ?? null) === 'cleaning_booking_details'
                && ($data['actorRole'] ?? null) === 'customer'
                && ($data['cancellationReason'] ?? null) === $reason;
        }
    );

    Notification::assertNotSentTo(
        $rejectedWorkerUser,
        BookingLifecycleNotification::class,
    );

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Cancelled)
        ->and($booking->workerAssignments()->where('worker_id', $worker->id)->value('status'))
        ->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled->value);
});

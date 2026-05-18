<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function (): void {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('sends worker-started-travel canonical notification to customer with standard keys', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel")
        ->assertOk();

    $notification = $customer->fresh()->notifications
        ->first(fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.worker_started_travel');

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe('worker_started_travel');
    expect($notification->data['module'])->toBe('cleaning');
    expect($notification->data['bookingId'])->toBe($booking->id);
    expect($notification->data['orderId'])->toBe($booking->id);
    expect($notification->data['status'])->toBe(CleaningBookingStatus::WorkerAssigned->value);
    expect($notification->data['action'])->toBe('worker_started_travel');
    expect($notification->data['deep_link_target'])->toBe('cleaning_order_details');
    expect($notification->data['occurred_at'])->toBeString();
    expect($notification->data['title'])->toBeString();
    expect($notification->data['message'])->toBeString();
});

it('sends completion-approved canonical notification to worker with standard keys', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHour(),
        'work_finished_at' => now()->subMinutes(5),
    ]);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/confirm")
        ->assertOk();

    $notification = $workerUser->fresh()->notifications
        ->first(fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.completion_approved');

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe('completion_approved');
    expect($notification->data['module'])->toBe('cleaning');
    expect($notification->data['bookingId'])->toBe($booking->id);
    expect($notification->data['orderId'])->toBe($booking->id);
    expect($notification->data['status'])->toBe(CleaningBookingStatus::Completed->value);
    expect($notification->data['action'])->toBe('completion_approved');
    expect($notification->data['deep_link_target'])->toBe('cleaning_booking_details');
    expect($notification->data['occurred_at'])->toBeString();
    expect($notification->data['title'])->toBeString();
    expect($notification->data['message'])->toBeString();
});

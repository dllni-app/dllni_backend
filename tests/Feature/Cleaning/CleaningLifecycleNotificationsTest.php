<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;

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
    expect($notification->data['deepLinkTarget'])->toBe('cleaning_order_details');
    expect($notification->data['canonicalType'])->toBe('cleaning.booking.worker_started_travel');
    expect($notification->data['args'])->toBeJson();
    expect(json_decode((string) $notification->data['args'], true))->toMatchArray([
        'route' => 'cleaning_order_details',
        'bookingId' => $booking->id,
        'orderId' => $booking->id,
        'action' => 'worker_started_travel',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
    ]);
    expect($notification->data['occurred_at'])->toBeString();
    expect($notification->data['title'])->toBeString();
    expect($notification->data['message'])->toBeString();
});

it('sends worker-confirmed canonical notification to customer when a worker accepts but the booking is not yet fulfilled', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.3,
    ]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'number_of_workers' => 2,
        'address_latitude' => 33.5,
        'address_longitude' => 36.3,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept")
        ->assertOk();

    $notification = $customer->fresh()->notifications
        ->first(fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.worker_confirmed');

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe('worker_confirmed');
    expect($notification->data['module'])->toBe('cleaning');
    expect($notification->data['bookingId'])->toBe($booking->id);
    expect($notification->data['orderId'])->toBe($booking->id);
    expect($notification->data['action'])->toBe('worker_confirmed');
    expect($notification->data['deep_link_target'])->toBe('cleaning_order_details');
    expect($notification->data['canonicalType'])->toBe('cleaning.booking.worker_confirmed');
    expect($notification->data['args'])->toBeJson();
    expect(json_decode((string) $notification->data['args'], true))->toMatchArray([
        'route' => 'cleaning_order_details',
        'bookingId' => $booking->id,
        'orderId' => $booking->id,
        'action' => 'worker_confirmed',
    ]);
    expect($notification->data['title'])->toBeString();
    expect($notification->data['message'])->toBeString();
});

it('marks preferred-worker booking as decision-required and notifies customer when preferred worker rejects a cleaning booking', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker,
        'number_of_workers' => 1,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'apartment',
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom_1',
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'display_label' => 'Bedroom 1',
        'weight' => 1,
        'planned_worker_slot' => 1,
        'planned_preferred_worker_id' => $worker->id,
        'assigned_worker_id' => null,
        'assignment_source' => null,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", [
        'reason' => 'Schedule conflict',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::Pending->value)
        ->assertJsonPath('data.assignmentMode', CleaningAssignmentMode::PreferredWorker->value)
        ->assertJsonPath('data.preferredWorkerId', $worker->id)
        ->assertJsonPath('data.convertedFromPreferredWorker', false)
        ->assertJsonPath('data.converted_from_preferred_worker', false)
        ->assertJsonPath('data.requiresPreferredWorkerRejectionDecision', true)
        ->assertJsonPath('data.requires_preferred_worker_rejection_decision', true)
        ->assertJsonPath('data.preferredWorkerRejectionDecisionStatus', CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_PENDING);

    $booking->refresh();

    expect($booking->status)->toBe(CleaningBookingStatus::Pending)
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::PreferredWorker->value)
        ->and($booking->preferred_worker_id)->toBe($worker->id)
        ->and($booking->converted_from_preferred_worker)->toBeFalse()
        ->and($booking->converted_from_preferred_worker_at)->toBeNull()
        ->and($booking->requiresPreferredWorkerRejectionDecision())->toBeTrue()
        ->and($booking->preferred_worker_rejection_worker_id)->toBe($worker->id)
        ->and($booking->preferred_worker_rejected_at)->not->toBeNull()
        ->and($booking->preferred_worker_rejection_decided_at)->toBeNull();

    $this->assertDatabaseHas('cleaning_booking_rooms', [
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom_1',
        'planned_preferred_worker_id' => $worker->id,
    ]);

    $this->assertDatabaseHas('cleaning_booking_worker_rejections', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'reason' => 'Schedule conflict',
    ]);

    $notification = $customer->fresh()->notifications
        ->first(fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.preferred_worker_rejected_decision_required');

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe('preferred_worker_rejection_decision_required');
    expect($notification->data['module'])->toBe('cleaning');
    expect($notification->data['bookingId'])->toBe($booking->id);
    expect($notification->data['orderId'])->toBe($booking->id);
    expect($notification->data['workerId'])->toBe($worker->id);
    expect($notification->data['propertyType'])->toBe('apartment');
    expect($notification->data['assignmentMode'])->toBe(CleaningAssignmentMode::PreferredWorker->value);
    expect($notification->data['requiresDecision'])->toBeTrue();
    expect($notification->data['requires_decision'])->toBeTrue();
    expect($notification->data['decisionStatus'])->toBe(CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_PENDING);
    expect($notification->data['status'])->toBe(CleaningBookingStatus::Pending->value);
    expect($notification->data['action'])->toBe('preferred_worker_rejection_decision_required');
    expect($notification->data['workerRejectMessage'])->toBe('Schedule conflict');
    expect($notification->data['worker_reject_message'])->toBe('Schedule conflict');
    expect($notification->data['deep_link_target'])->toBe('cleaning_order_details');
    expect($notification->data['deepLinkTarget'])->toBe('cleaning_order_details');
    expect($notification->data['canonicalType'])->toBe('cleaning.booking.preferred_worker_rejected_decision_required');
    expect($notification->data['args'])->toBeJson();
    expect(json_decode((string) $notification->data['args'], true))->toMatchArray([
        'route' => 'cleaning_order_details',
        'bookingId' => $booking->id,
        'orderId' => $booking->id,
        'action' => 'preferred_worker_rejection_decision_required',
        'status' => CleaningBookingStatus::Pending->value,
        'assignmentMode' => CleaningAssignmentMode::PreferredWorker->value,
        'requiresDecision' => true,
    ]);
    expect($notification->data['title'])->toBeString();
    expect($notification->data['message'])->toBeString();
    expect($customer->fresh()->notifications->contains(
        fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.updated'
    ))->toBeFalse();
});

it('sends preferred-worker decision-required notification to customer when preferred worker rejects an event assistance booking', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker,
        'number_of_workers' => 1,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'event_assistance',
        'property_details' => [
            'address' => 'Event hall',
            'eventType' => 'family_dinner',
            'guestCount' => 40,
            'venueType' => 'house',
            'customService' => 'Serving help',
            'hours' => 3,
        ],
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.assignmentMode', CleaningAssignmentMode::PreferredWorker->value)
        ->assertJsonPath('data.requiresPreferredWorkerRejectionDecision', true);

    $notification = $customer->fresh()->notifications
        ->first(fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.preferred_worker_rejected_decision_required');

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe('preferred_worker_rejection_decision_required');
    expect($notification->data['bookingId'])->toBe($booking->id);
    expect($notification->data['orderId'])->toBe($booking->id);
    expect($notification->data['workerId'])->toBe($worker->id);
    expect($notification->data['propertyType'])->toBe('event_assistance');
    expect($notification->data['assignmentMode'])->toBe(CleaningAssignmentMode::PreferredWorker->value);
    expect($notification->data['requiresDecision'])->toBeTrue();
    expect($notification->data['action'])->toBe('preferred_worker_rejection_decision_required');
    expect($notification->data['deep_link_target'])->toBe('cleaning_order_details');
    expect($notification->data['canonicalType'])->toBe('cleaning.booking.preferred_worker_rejected_decision_required');
});

it('does not notify customer when an unassigned open-count worker rejects a public pending booking', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount,
        'number_of_workers' => 2,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject", [
        'reason' => 'Cannot take this booking',
    ])->assertOk();

    $notification = $customer->fresh()->notifications
        ->first(fn ($item): bool => ($item->data['canonical_type'] ?? null) === 'cleaning.booking.worker_rejected');

    expect($notification)->toBeNull();
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
    expect($notification->data['deepLinkTarget'])->toBe('cleaning_booking_details');
    expect($notification->data['canonicalType'])->toBe('cleaning.booking.completion_approved');
    expect($notification->data['args'])->toBeJson();
    expect(json_decode((string) $notification->data['args'], true))->toMatchArray([
        'route' => 'cleaning_booking_details',
        'bookingId' => $booking->id,
        'orderId' => $booking->id,
        'action' => 'completion_approved',
        'status' => CleaningBookingStatus::Completed->value,
    ]);
    expect($notification->data['occurred_at'])->toBeString();
    expect($notification->data['title'])->toBeString();
    expect($notification->data['message'])->toBeString();
});

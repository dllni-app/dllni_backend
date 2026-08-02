<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Queue;
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

function preferredWorkerDecisionBooking(User $customer, Worker $worker, array $overrides = []): CleaningBooking
{
    $booking = CleaningBooking::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker,
        'number_of_workers' => 1,
        'billing_policy_id' => CleaningBillingPolicy::first()->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'property_type' => 'apartment',
        'converted_from_preferred_worker' => false,
        'converted_from_preferred_worker_at' => null,
        'preferred_worker_rejection_worker_id' => $worker->id,
        'preferred_worker_rejected_at' => now()->subMinute(),
        'preferred_worker_rejection_decision_status' => CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_PENDING,
        'preferred_worker_rejection_decided_at' => null,
    ], $overrides));

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

    return $booking;
}

it('returns pending preferred worker rejection decisions for the current customer only', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);

    $pending = preferredWorkerDecisionBooking($customer, $worker);
    preferredWorkerDecisionBooking($otherCustomer, $worker);
    preferredWorkerDecisionBooking($customer, $worker, [
        'preferred_worker_rejection_decision_status' => CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CONVERTED_TO_OPEN,
        'preferred_worker_rejection_decided_at' => now(),
    ]);

    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/user/cleaning/preferred-worker-rejection/decisions/pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.requiresPreferredWorkerRejectionDecision', true)
        ->assertJsonPath('data.0.preferredWorkerRejectionDecisionStatus', CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_PENDING);
});

it('converts a pending preferred worker rejection decision to an open cleaning request', function (): void {
    Queue::fake([NotifyEligibleWorkersNewOrderJob::class]);

    $customer = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $booking = preferredWorkerDecisionBooking($customer, $worker);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/preferred-worker-rejection/decision", [
        'decision' => 'convert_to_open',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::Pending->value)
        ->assertJsonPath('data.assignmentMode', CleaningAssignmentMode::OpenCount->value)
        ->assertJsonPath('data.preferredWorkerId', null)
        ->assertJsonPath('data.convertedFromPreferredWorker', true)
        ->assertJsonPath('data.requiresPreferredWorkerRejectionDecision', false)
        ->assertJsonPath('data.preferredWorkerRejectionDecisionStatus', CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CONVERTED_TO_OPEN);

    $booking->refresh();

    expect($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::OpenCount->value)
        ->and($booking->preferred_worker_id)->toBeNull()
        ->and($booking->converted_from_preferred_worker)->toBeTrue()
        ->and($booking->converted_from_preferred_worker_at)->not->toBeNull()
        ->and($booking->preferred_worker_rejection_decision_status)->toBe(CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CONVERTED_TO_OPEN)
        ->and($booking->preferred_worker_rejection_decided_at)->not->toBeNull();

    $this->assertDatabaseHas('cleaning_booking_rooms', [
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom_1',
        'planned_preferred_worker_id' => null,
    ]);

    Queue::assertPushed(NotifyEligibleWorkersNewOrderJob::class);
});

it('cancels a pending preferred worker rejection decision without fees', function (): void {
    $customer = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $booking = preferredWorkerDecisionBooking($customer, $worker, [
        'cancellation_fee' => 5000,
    ]);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/preferred-worker-rejection/decision", [
        'decision' => 'cancel',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::Cancelled->value)
        ->assertJsonPath('data.cancellationFee', 0)
        ->assertJsonPath('data.requiresPreferredWorkerRejectionDecision', false)
        ->assertJsonPath('data.preferredWorkerRejectionDecisionStatus', CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CANCELLED);

    $booking->refresh();

    expect($booking->status)->toBe(CleaningBookingStatus::Cancelled)
        ->and($booking->cancelled_by_role)->toBe('customer')
        ->and((float) $booking->cancellation_fee)->toBe(0.0)
        ->and($booking->preferred_worker_rejection_decision_status)->toBe(CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_CANCELLED)
        ->and($booking->preferred_worker_rejection_decided_at)->not->toBeNull();
});

it('rejects duplicate preferred worker rejection decisions', function (): void {
    $customer = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $booking = preferredWorkerDecisionBooking($customer, $worker);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/preferred-worker-rejection/decision", [
        'decision' => 'cancel',
    ])->assertOk();

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/preferred-worker-rejection/decision", [
        'decision' => 'convert_to_open',
    ])->assertStatus(422);
});

it('does not allow another customer to decide the preferred worker rejection', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $booking = preferredWorkerDecisionBooking($customer, $worker);

    Sanctum::actingAs($otherCustomer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/preferred-worker-rejection/decision", [
        'decision' => 'cancel',
    ])->assertNotFound();
});

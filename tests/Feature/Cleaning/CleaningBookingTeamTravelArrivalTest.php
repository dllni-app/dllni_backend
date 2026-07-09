<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

beforeEach(function () {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('allows another team worker to start travel and arrive even when the global booking was already in progress', function (): void {
    [$workerOneUser, $workerOne] = createTravelArrivalWorker('team-travel-one@example.com');
    [$workerTwoUser, $workerTwo] = createTravelArrivalWorker('team-travel-two@example.com');

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
        'number_of_workers' => 2,
        'work_started_at' => now()->subMinutes(20),
    ]);

    createTravelArrivalAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::InProgress, [
        'start_approved_at' => now()->subMinutes(20),
        'work_started_at' => now()->subMinutes(20),
    ]);
    createTravelArrivalAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);

    Sanctum::actingAs($workerTwoUser);
    $travelResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-travel");

    $travelResponse->assertOk();
    $travelResponse->assertJsonPath('data.order_status', CleaningBookingStatus::InProgress->value);
    $travelResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value);

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
    ]);
    expect(DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $workerTwo->id)
        ->value('started_travel_at'))->not->toBeNull();

    $arriveResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive");

    $arriveResponse->assertOk();
    $arriveResponse->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingStartVerification->value);
    $arriveResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
    ]);

    expect($workerOneUser)->toBeInstanceOf(User::class);
});

it('keeps team worker arrival assignment-aware when only the global travel timestamp exists', function (): void {
    [$workerOneUser, $workerOne] = createTravelArrivalWorker('team-global-travel-one@example.com');
    [$workerTwoUser, $workerTwo] = createTravelArrivalWorker('team-global-travel-two@example.com');
    $startedTravelAt = now()->subMinutes(10);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2,
        'started_travel_at' => $startedTravelAt,
    ]);

    createTravelArrivalAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, [
        'started_travel_at' => $startedTravelAt,
    ]);
    createTravelArrivalAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);

    Sanctum::actingAs($workerTwoUser);
    $arriveResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive");

    $arriveResponse->assertOk();
    $arriveResponse->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingStartVerification->value);
    $arriveResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value);

    expect(DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $workerTwo->id)
        ->value('started_travel_at'))->not->toBeNull();

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
    ]);
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification->value,
    ]);

    expect($workerOneUser)->toBeInstanceOf(User::class);
});

it('requires a separate customer security confirmation for every arrived worker and lets each worker start and finish independently', function (): void {
    $customer = User::factory()->create(['email' => 'team-code-customer@example.com']);
    [$workerOneUser, $workerOne] = createTravelArrivalWorker('team-code-worker-one@example.com');
    [$workerTwoUser, $workerTwo] = createTravelArrivalWorker('team-code-worker-two@example.com');
    $startedTravelAt = now()->subMinutes(5);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2,
        'started_travel_at' => $startedTravelAt,
        'work_started_at' => null,
    ]);

    createTravelArrivalAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, [
        'started_travel_at' => $startedTravelAt,
    ]);
    createTravelArrivalAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, [
        'started_travel_at' => $startedTravelAt,
    ]);

    Sanctum::actingAs($workerOneUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive")->assertOk();
    $workerOneCode = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code")
        ->assertOk()
        ->json('data.securityCode');

    Sanctum::actingAs($workerTwoUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive")->assertOk();
    $workerTwoCode = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code")
        ->assertOk()
        ->json('data.securityCode');

    expect($workerOneCode)->not->toBe($workerTwoCode);

    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => $workerOneCode,
    ])->assertOk();

    Sanctum::actingAs($workerOneUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value);

    Sanctum::actingAs($workerOneUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", [
        'completionMessage' => 'Worker one finished.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingCustomerCompletion->value)
        ->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value)
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingCustomerCompletion->value);

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
    ]);

    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => $workerTwoCode,
    ])->assertOk()
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingCustomerCompletion->value)
        ->assertJsonCount(1, 'data.completionRequests')
        ->assertJsonPath('data.completionRequests.0.workerId', $workerOne->id);

    Sanctum::actingAs($workerTwoUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value)
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingCustomerCompletion->value);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", [
        'completionMessage' => 'Worker two finished.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingCustomerCompletion->value)
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingCustomerCompletion->value)
        ->assertJsonCount(2, 'data.completionRequests');

    $workerTwoAssignmentId = (int) DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $workerTwo->id)
        ->value('id');

    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/confirm", [
        'workerId' => $workerOne->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingCustomerCompletion->value)
        ->assertJsonCount(1, 'data.completionRequests')
        ->assertJsonPath('data.completionRequests.0.workerId', $workerTwo->id)
        ->assertJsonPath('data.workerLifecycleSummary.completed', 1)
        ->assertJsonPath('data.workerLifecycleSummary.awaitingCustomerCompletion', 1);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/confirm", [
        'assignmentId' => $workerTwoAssignmentId,
    ])
        ->assertOk()
        ->assertJsonPath('data.order_status', CleaningBookingStatus::Completed->value)
        ->assertJsonPath('data.workerLifecycleSummary.completed', 2)
        ->assertJsonPath('data.workerLifecycleSummary.isFullyCompleted', true);

    $this->assertDatabaseHas('booking_security_codes', [
        'booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
    ]);
    $this->assertDatabaseHas('booking_security_codes', [
        'booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
    ]);
});

it('targets completion extension requests to the selected worker only', function (): void {
    $customer = User::factory()->create(['email' => 'team-extension-customer@example.com']);
    [$workerOneUser, $workerOne] = createTravelArrivalWorker('team-extension-worker-one@example.com');
    [$workerTwoUser, $workerTwo] = createTravelArrivalWorker('team-extension-worker-two@example.com');

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'number_of_workers' => 2,
        'work_started_at' => now()->subHour(),
    ]);

    createTravelArrivalAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion, [
        'start_approved_at' => now()->subHour(),
        'work_started_at' => now()->subHour(),
        'work_finished_at' => now()->subMinutes(10),
        'worker_completion_message' => 'Worker one finished.',
        'worker_finished_cleaning_services' => json_encode([['name' => 'Kitchen']]),
        'worker_finished_property_rooms' => json_encode([['roomKey' => 'kitchen', 'displayLabel' => 'Kitchen']]),
    ]);
    createTravelArrivalAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion, [
        'start_approved_at' => now()->subHour(),
        'work_started_at' => now()->subHour(),
        'work_finished_at' => now()->subMinutes(5),
        'worker_completion_message' => 'Worker two finished.',
        'worker_finished_cleaning_services' => json_encode([['name' => 'Bedroom']]),
        'worker_finished_property_rooms' => json_encode([['roomKey' => 'bedroom', 'displayLabel' => 'Bedroom']]),
    ]);

    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.completionRequests')
        ->assertJsonPath('data.workerLifecycleSummary.awaitingCustomerCompletion', 2);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/extend-time", [
        'workerId' => $workerTwo->id,
        'additionalMinutes' => 30,
        'message' => 'Please continue this worker only.',
    ])->assertOk()
        ->assertJsonPath('data.order_status', CleaningBookingStatus::TimeExtensionRequested->value)
        ->assertJsonPath('data.workerLifecycleSummary.awaitingCustomerCompletion', 1)
        ->assertJsonPath('data.workerLifecycleSummary.timeExtensionRequested', 1);

    $warning = CleaningTimeWarning::query()
        ->where('booking_id', $booking->id)
        ->where('worker_id', $workerTwo->id)
        ->latest('id')
        ->first();

    expect($warning)->not->toBeNull();

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value,
    ]);

    Sanctum::actingAs($workerTwoUser);
    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.workerId', $workerTwo->id);

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
    ]);
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
    ]);

    expect($workerOneUser)->toBeInstanceOf(User::class);
});

it('still requires explicit travel start before arrival for a one-worker booking', function (): void {
    [$workerUser, $worker] = createTravelArrivalWorker('single-worker-arrival@example.com');

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 1,
        'started_travel_at' => null,
    ]);

    createTravelArrivalAssignment($booking, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);

    Sanctum::actingAs($workerUser);
    $arriveResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/arrive");

    $arriveResponse->assertUnprocessable();
    $arriveResponse->assertJsonPath('errors.status.0', 'Worker must have started travel before marking arrival.');
});

function createTravelArrivalWorker(string $email): array
{
    $user = User::factory()->create(['email' => $email]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.3,
    ]);

    return [$user, $worker];
}

function createTravelArrivalAssignment(
    CleaningBooking $booking,
    Worker $worker,
    CleaningBookingWorkerAssignmentStatus $status,
    array $overrides = [],
): void {
    DB::table('cleaning_booking_worker_assignments')->insert(array_merge([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => $status->value,
        'accepted_at' => now()->subMinutes(30),
        'started_travel_at' => null,
        'arrived_at' => null,
        'start_approved_at' => null,
        'work_started_at' => null,
        'work_finished_at' => null,
        'worker_completion_message' => null,
        'worker_finished_cleaning_services' => null,
        'worker_finished_property_rooms' => null,
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => (string) config('app.currency', 'SYP'),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

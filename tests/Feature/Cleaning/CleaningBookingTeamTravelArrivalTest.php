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

it('requires a separate customer security confirmation for every arrived worker', function (): void {
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
    $confirmOne = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => $workerOneCode,
    ]);

    $confirmOne->assertOk();
    $confirmOne->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingStartVerification->value);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::StartApproved->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
    ]);

    Sanctum::actingAs($workerOneUser);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value)
        ->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::StartApproved->value);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value)
        ->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingStartVerification->value);

    Sanctum::actingAs($workerTwoUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Customer must verify the security code before work can start.');

    Sanctum::actingAs($customer);
    $confirmTwo = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => $workerTwoCode,
    ]);

    $confirmTwo->assertOk();
    $confirmTwo->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);

    Sanctum::actingAs($workerTwoUser);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value)
        ->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::StartApproved->value);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value)
        ->assertJsonPath('data.order_status', CleaningBookingStatus::InProgress->value);

    $this->assertDatabaseHas('booking_security_codes', [
        'booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
    ]);
    $this->assertDatabaseHas('booking_security_codes', [
        'booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
    ]);
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

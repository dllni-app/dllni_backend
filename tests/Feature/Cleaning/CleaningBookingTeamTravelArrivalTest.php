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

it('returns start confirmation status to arrived team workers after the customer verifies the code', function (): void {
    [$workerOneUser, $workerOne] = createTravelArrivalWorker('team-confirmed-one@example.com');
    [$workerTwoUser, $workerTwo] = createTravelArrivalWorker('team-confirmed-two@example.com');
    $confirmedAt = now();
    $arrivedAt = now()->subMinutes(2);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        'number_of_workers' => 2,
        'started_travel_at' => now()->subMinutes(5),
        'arrived_at' => $arrivedAt,
        'customer_confirmed_at' => $confirmedAt,
        'work_started_at' => null,
    ]);

    createTravelArrivalAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification, [
        'started_travel_at' => now()->subMinutes(5),
        'arrived_at' => $arrivedAt,
    ]);
    createTravelArrivalAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification, [
        'started_travel_at' => now()->subMinutes(5),
        'arrived_at' => $arrivedAt,
    ]);

    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'code' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'attempts' => 1,
        'last_attempt_at' => $confirmedAt,
        'consumed_at' => $confirmedAt,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($workerOneUser);
    $showWorkerOne = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $showWorkerOne->assertOk();
    $showWorkerOne->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);
    $showWorkerOne->assertJsonPath('data.worker_order_status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);
    $showWorkerOne->assertJsonPath('data.myAssignment.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);

    $startWorkerOne = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");
    $startWorkerOne->assertOk();
    $startWorkerOne->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value);
    $startWorkerOne->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);

    Sanctum::actingAs($workerTwoUser);
    $showWorkerTwo = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $showWorkerTwo->assertOk();
    $showWorkerTwo->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);
    $showWorkerTwo->assertJsonPath('data.worker_order_status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);
    $showWorkerTwo->assertJsonPath('data.myAssignment.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);

    $startWorkerTwo = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");
    $startWorkerTwo->assertOk();
    $startWorkerTwo->assertJsonPath('data.status', CleaningBookingStatus::InProgress->value);
    $startWorkerTwo->assertJsonPath('data.order_status', CleaningBookingStatus::InProgress->value);
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

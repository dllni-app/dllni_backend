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

it('keeps team booking waiting until every worker starts their own assignment', function (): void {
    [$workerOneUser, $workerOne] = createCleaningWorker('team-start-one@example.com');
    [$workerTwoUser, $workerTwo] = createCleaningWorker('team-start-two@example.com');

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        'number_of_workers' => 2,
        'arrived_at' => now()->subMinutes(2),
        'customer_confirmed_at' => now()->subMinute(),
    ]);

    createTeamAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification);
    createTeamAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification);
    createConsumedSecurityCode($booking);

    Sanctum::actingAs($workerOneUser);
    $firstResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");

    $firstResponse->assertOk();
    $firstResponse->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);
    $firstResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::InProgress->value);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
    ]);

    Sanctum::actingAs($workerTwoUser);
    $secondResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");

    $secondResponse->assertOk();
    $secondResponse->assertJsonPath('data.order_status', CleaningBookingStatus::InProgress->value);
    $secondResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::InProgress->value);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
});

it('starts an assignment-backed one-worker booking after customer verification', function (): void {
    [$workerUser, $worker] = createCleaningWorker('assignment-one-worker@example.com');
    $customer = User::factory()->create(['email' => 'assignment-one-worker-customer@example.com']);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification,
        'number_of_workers' => 1,
        'arrived_at' => now()->subMinutes(2),
    ]);

    createTeamAssignment($booking, $worker, CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification);
    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'code' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'consumed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => '1234',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingWorkerStartConfirmation->value);

    Sanctum::actingAs($workerUser);
    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work");

    $response->assertOk()
        ->assertJsonPath('data.order_status', CleaningBookingStatus::InProgress->value)
        ->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::InProgress->value);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
    ]);
});

it('keeps team booking active until every worker completes their own assignment', function (): void {
    [$workerOneUser, $workerOne] = createCleaningWorker('team-complete-one@example.com');
    [$workerTwoUser, $workerTwo] = createCleaningWorker('team-complete-two@example.com');

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
        'number_of_workers' => 2,
        'work_started_at' => now()->subHour(),
    ]);

    createTeamAssignment($booking, $workerOne, CleaningBookingWorkerAssignmentStatus::InProgress, [
        'work_started_at' => now()->subHour(),
        'start_approved_at' => now()->subHour(),
    ]);
    createTeamAssignment($booking, $workerTwo, CleaningBookingWorkerAssignmentStatus::InProgress, [
        'work_started_at' => now()->subHour(),
        'start_approved_at' => now()->subHour(),
    ]);

    Sanctum::actingAs($workerOneUser);
    $firstResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", [
        'completionMessage' => 'Worker one finished assigned rooms.',
    ]);

    $firstResponse->assertOk();
    $firstResponse->assertJsonPath('data.order_status', CleaningBookingStatus::InProgress->value);
    $firstResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
    ]);

    Sanctum::actingAs($workerTwoUser);
    $secondResponse = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", [
        'completionMessage' => 'Worker two finished assigned rooms.',
    ]);

    $secondResponse->assertOk();
    $secondResponse->assertJsonPath('data.order_status', CleaningBookingStatus::AwaitingCustomerCompletion->value);
    $secondResponse->assertJsonPath('data.worker_order_status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
    ]);
});

function createCleaningWorker(string $email): array
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

function createTeamAssignment(
    CleaningBooking $booking,
    Worker $worker,
    CleaningBookingWorkerAssignmentStatus $status,
    array $overrides = [],
): void {
    DB::table('cleaning_booking_worker_assignments')->insert(array_merge([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => $status->value,
        'accepted_at' => now()->subMinutes(10),
        'started_travel_at' => now()->subMinutes(5),
        'arrived_at' => now()->subMinutes(2),
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

function createConsumedSecurityCode(CleaningBooking $booking): void
{
    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'code' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'attempts' => 1,
        'expires_at' => now()->addMinutes(10),
        'consumed_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

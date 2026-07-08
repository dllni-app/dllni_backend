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

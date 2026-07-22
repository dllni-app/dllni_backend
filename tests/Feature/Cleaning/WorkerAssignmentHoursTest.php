<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Support\WorkerAssignmentHoursResolver;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 12:00:00'));

    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('resolves proportional hours from assigned room weights', function (): void {
    $booking = CleaningBooking::factory()->make([
        'number_of_workers' => 2,
        'total_hours' => 7.5,
        'estimated_hours' => 7.5,
    ]);

    $assignment = new CleaningBookingWorkerAssignment([
        'worker_id' => 1,
        'rooms_weight' => 3.0,
    ]);

    $rooms = collect([
        new CleaningBookingRoom(['weight' => 3.0, 'assigned_worker_id' => 1]),
        new CleaningBookingRoom(['weight' => 2.0, 'assigned_worker_id' => 2]),
    ]);

    // 7.5 * (3/5) = 4.5
    expect(WorkerAssignmentHoursResolver::resolve($booking, $assignment, $rooms))->toBe(4.5);
});

it('returns booking hours for single-worker bookings', function (): void {
    $booking = CleaningBooking::factory()->make([
        'number_of_workers' => 1,
        'total_hours' => 7.5,
        'estimated_hours' => 7.5,
    ]);

    $assignment = new CleaningBookingWorkerAssignment([
        'worker_id' => 1,
        'rooms_weight' => 3.0,
    ]);

    expect(WorkerAssignmentHoursResolver::resolve($booking, $assignment))->toBe(7.5);
});

it('returns personalized totalHours and workTimer for each team worker', function (): void {
    [$workerOneUser, $workerOne] = createWorkerAssignmentHoursWorker('hours-worker-one@example.com');
    [$workerTwoUser, $workerTwo] = createWorkerAssignmentHoursWorker('hours-worker-two@example.com');

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $workerOne->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
        'number_of_workers' => 2,
        'total_hours' => 5,
        'estimated_hours' => 5,
        'work_started_at' => now()->subMinutes(30),
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerOne->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'accepted_at' => now()->subHour(),
        'work_started_at' => now()->subMinutes(30),
        'room_count' => 1,
        'rooms_weight' => 3.0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $workerTwo->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'accepted_at' => now()->subHour(),
        'work_started_at' => now()->subMinutes(30),
        'room_count' => 1,
        'rooms_weight' => 2.0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom-1',
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'display_label' => 'Bedroom 1',
        'weight' => 3.0,
        'planned_worker_slot' => 1,
        'assigned_worker_id' => $workerOne->id,
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bathroom-1',
        'room_type' => 'bathroom',
        'room_size' => 'medium',
        'display_label' => 'Bathroom 1',
        'weight' => 2.0,
        'planned_worker_slot' => 2,
        'assigned_worker_id' => $workerTwo->id,
    ]);

    Sanctum::actingAs($workerOneUser);
    $workerOneResponse = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");
    $workerOneResponse->assertOk();
    expect((float) $workerOneResponse->json('data.bookingTotalHours'))->toBe(5.0);
    expect((float) $workerOneResponse->json('data.totalHours'))->toBe(3.0);
    expect((float) $workerOneResponse->json('data.myAssignment.totalHours'))->toBe(3.0);
    expect((float) $workerOneResponse->json('data.workTimer.durationHours'))->toBe(3.0);
    expect($workerOneResponse->json('data.workTimer.source.durationField'))->toBe('assignment.total_hours');

    Sanctum::actingAs($workerTwoUser);
    $workerTwoResponse = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");
    $workerTwoResponse->assertOk();
    expect((float) $workerTwoResponse->json('data.bookingTotalHours'))->toBe(5.0);
    expect((float) $workerTwoResponse->json('data.totalHours'))->toBe(2.0);
    expect((float) $workerTwoResponse->json('data.myAssignment.totalHours'))->toBe(2.0);
    expect((float) $workerTwoResponse->json('data.workTimer.durationHours'))->toBe(2.0);
    expect($workerTwoResponse->json('data.workTimer.source.durationField'))->toBe('assignment.total_hours');
});

it('keeps full booking hours for customer and single-worker views', function (): void {
    $customer = User::factory()->create(['email' => 'hours-customer@example.com']);
    [$workerUser, $worker] = createWorkerAssignmentHoursWorker('hours-single-worker@example.com');

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress,
        'number_of_workers' => 1,
        'total_hours' => 4,
        'estimated_hours' => 4,
        'work_started_at' => now()->subMinutes(20),
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'accepted_at' => now()->subHour(),
        'work_started_at' => now()->subMinutes(20),
        'room_count' => 1,
        'rooms_weight' => 2.0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    Sanctum::actingAs($customer);
    $customerResponse = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");
    $customerResponse->assertOk();
    expect((float) $customerResponse->json('data.totalHours'))->toBe(4.0);
    expect((float) $customerResponse->json('data.bookingTotalHours'))->toBe(4.0);
    expect($customerResponse->json('data.workTimer.source.durationField'))->toBe('total_hours');

    Sanctum::actingAs($workerUser);
    $workerResponse = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");
    $workerResponse->assertOk();
    expect((float) $workerResponse->json('data.totalHours'))->toBe(4.0);
    expect((float) $workerResponse->json('data.myAssignment.totalHours'))->toBe(4.0);
    expect($workerResponse->json('data.workTimer.source.durationField'))->toBe('total_hours');
});

/**
 * @return array{0: User, 1: Worker}
 */
function createWorkerAssignmentHoursWorker(string $email): array
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

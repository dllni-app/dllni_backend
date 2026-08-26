<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\DashboardPermissionsSeeder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
});

it('returns independent worker tracking state to an authorized dashboard admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $customer = User::factory()->create();
    $firstWorker = Worker::factory()->create([
        'user_id' => User::factory()->create(['name' => 'عامل أول'])->id,
    ]);
    $secondWorker = Worker::factory()->create([
        'user_id' => User::factory()->create(['name' => 'عامل ثاني'])->id,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $firstWorker->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'neighborhood_name' => 'المزة',
    ]);

    createDashboardTrackingAssignment($booking, $firstWorker, [
        'last_latitude' => 33.5201,
        'last_longitude' => 36.2811,
        'location_updated_at' => now(),
    ]);
    createDashboardTrackingAssignment($booking, $secondWorker, [
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
        'last_latitude' => 33.5182,
        'last_longitude' => 36.2894,
        'location_updated_at' => now(),
        'arrived_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->getJson(route('admin.cleaning-bookings.tracking', ['cleaning_booking' => $booking->id]))
        ->assertOk()
        ->assertJsonPath('data.bookingId', $booking->id)
        ->assertJsonPath('data.requiredWorkers', 2)
        ->assertJsonPath('data.acceptedWorkers', 2)
        ->assertJsonPath('data.remainingWorkers', 0)
        ->assertJsonPath('data.destination.latitude', 33.5138)
        ->assertJsonPath('data.destination.longitude', 36.2765)
        ->assertJsonCount(2, 'data.workers');

    $workers = collect($response->json('data.workers'))->keyBy('workerId');

    expect((float) $workers[$firstWorker->id]['latitude'])->toBe(33.5201)
        ->and((float) $workers[$firstWorker->id]['longitude'])->toBe(36.2811)
        ->and($workers[$firstWorker->id]['isTravelling'])->toBeTrue()
        ->and((float) $workers[$secondWorker->id]['latitude'])->toBe(33.5182)
        ->and((float) $workers[$secondWorker->id]['longitude'])->toBe(36.2894)
        ->and($workers[$secondWorker->id]['isTravelling'])->toBeFalse()
        ->and($workers[$secondWorker->id]['statusLabel'])->toBe('بانتظار التحقق من البدء');
});

it('rejects dashboard tracking access without booking view permission', function (): void {
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
    ]);
    $unauthorizedUser = User::factory()->create();

    $this->actingAs($unauthorizedUser)
        ->getJson(route('admin.cleaning-bookings.tracking', ['cleaning_booking' => $booking->id]))
        ->assertForbidden();
});

function createDashboardTrackingAssignment(
    CleaningBooking $booking,
    Worker $worker,
    array $overrides = [],
): CleaningBookingWorkerAssignment {
    return CleaningBookingWorkerAssignment::query()->create(array_merge([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now()->subHour(),
        'started_travel_at' => now()->subMinutes(10),
        'arrived_at' => null,
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ], $overrides));
}

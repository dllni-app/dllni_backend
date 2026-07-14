<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

it('persists and restores independent locations for every worker in a team booking', function (): void {
    Event::fake([WorkerLocationUpdated::class]);

    $customer = User::factory()->create();
    $firstUser = User::factory()->create();
    $firstWorker = Worker::factory()->create(['user_id' => $firstUser->id]);
    $secondUser = User::factory()->create();
    $secondWorker = Worker::factory()->create(['user_id' => $secondUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $firstWorker->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    createTrackingAssignment($booking, $firstWorker);
    createTrackingAssignment($booking, $secondWorker);

    Sanctum::actingAs($firstUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ])->assertOk()->assertJsonPath('data.ignored', false);

    Sanctum::actingAs($secondUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5250,
        'longitude' => 36.2890,
    ])->assertOk()->assertJsonPath('data.ignored', false);

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $firstWorker->id,
        'last_latitude' => 33.5138,
        'last_longitude' => 36.2765,
    ]);
    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $secondWorker->id,
        'last_latitude' => 33.5250,
        'last_longitude' => 36.2890,
    ]);

    Sanctum::actingAs($customer);
    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/worker-locations")
        ->assertOk()
        ->assertJsonPath('meta.bookingId', $booking->id)
        ->assertJsonCount(2, 'data');

    $locations = collect($response->json('data'))->keyBy('workerId');

    expect((float) $locations[$firstWorker->id]['latitude'])->toBe(33.5138)
        ->and((float) $locations[$firstWorker->id]['longitude'])->toBe(36.2765)
        ->and((float) $locations[$secondWorker->id]['latitude'])->toBe(33.525)
        ->and((float) $locations[$secondWorker->id]['longitude'])->toBe(36.289);

    Event::assertDispatchedTimes(WorkerLocationUpdated::class, 2);
});

it('keeps travelling workers visible after another team worker arrives', function (): void {
    $customer = User::factory()->create();
    $firstUser = User::factory()->create();
    $firstWorker = Worker::factory()->create(['user_id' => $firstUser->id]);
    $secondUser = User::factory()->create();
    $secondWorker = Worker::factory()->create(['user_id' => $secondUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $firstWorker->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::AwaitingStartVerification,
    ]);

    createTrackingAssignment($booking, $firstWorker, [
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
        'arrived_at' => now(),
        'last_latitude' => 33.5100,
        'last_longitude' => 36.2700,
        'location_updated_at' => now(),
    ]);
    createTrackingAssignment($booking, $secondWorker, [
        'last_latitude' => 33.5300,
        'last_longitude' => 36.3000,
        'location_updated_at' => now(),
    ]);

    Sanctum::actingAs($customer);
    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/worker-locations")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $locations = collect($response->json('data'))->keyBy('workerId');

    expect($locations[$firstWorker->id]['arrivedAt'])->not->toBeNull()
        ->and($locations[$secondWorker->id]['arrivedAt'])->toBeNull()
        ->and($locations[$secondWorker->id]['startedTravelAt'])->not->toBeNull()
        ->and((float) $locations[$secondWorker->id]['latitude'])->toBe(33.53);
});

function createTrackingAssignment(
    CleaningBooking $booking,
    Worker $worker,
    array $overrides = [],
): CleaningBookingWorkerAssignment {
    return CleaningBookingWorkerAssignment::query()->create(array_merge([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now()->subHour(),
        'started_travel_at' => now()->subMinutes(5),
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

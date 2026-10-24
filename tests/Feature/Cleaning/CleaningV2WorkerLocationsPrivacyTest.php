<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

it('fails closed on booking-wide worker locations for a partial V2 worker', function (): void {
    $customer = User::factory()->create();
    $firstUser = User::factory()->create(['name' => 'Owned V2 Worker', 'phone' => '+963900000001']);
    $firstWorker = Worker::factory()->create(['user_id' => $firstUser->id]);
    $siblingUser = User::factory()->create(['name' => 'Sibling Secret Worker', 'phone' => '+963900000002']);
    $siblingWorker = Worker::factory()->create(['user_id' => $siblingUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $firstWorker->id,
        'number_of_workers' => 2,
        'booking_kind' => 'standard',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'property_details' => [
            '_time_period_v2' => [
                'rooms' => [['clientKey' => 'v2-a'], ['clientKey' => 'v2-b']],
                'visits' => [['sequence' => 1], ['sequence' => 2]],
            ],
            '_supervisor_pricing' => ['hourlyRate' => 0, 'fee' => 0],
        ],
    ]);
    $sessions = collect([1, 2])->map(fn (int $sequence): CleaningBookingSession => $booking->sessions()->create([
        'sequence' => $sequence,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays($sequence + 2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'searching',
        'status' => 'worker_assigned',
    ]));
    $sessions[0]->workerAssignments()->create([
        'worker_id' => $firstWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $sessions[1]->workerAssignments()->create([
        'worker_id' => $siblingWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $firstWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
        'last_latitude' => 33.5101,
        'last_longitude' => 36.2911,
        'location_updated_at' => now(),
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $siblingWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
        'last_latitude' => 33.5999,
        'last_longitude' => 36.3999,
        'location_updated_at' => now(),
    ]);
    Sanctum::actingAs($firstUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/worker-locations")
        ->assertNotFound();

    expect($response->getContent())
        ->not->toContain('Sibling Secret Worker')
        ->not->toContain('+963900000002')
        ->not->toContain('33.5999')
        ->not->toContain('36.3999');
});

it('preserves legacy solo worker location tracking', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create(['name' => 'Legacy Solo Worker', 'phone' => '+963911111111']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'booking_kind' => 'standard',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'last_worker_latitude' => 33.5001,
        'last_worker_longitude' => 36.2001,
        'worker_location_updated_at' => now(),
    ]);
    Sanctum::actingAs($workerUser);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/worker-locations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.workerId', $worker->id)
        ->assertJsonPath('data.0.worker.name', 'Legacy Solo Worker')
        ->assertJsonPath('data.0.latitude', 33.5001)
        ->assertJsonPath('data.0.longitude', 36.2001);
});

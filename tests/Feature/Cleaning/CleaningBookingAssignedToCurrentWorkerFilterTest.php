<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

use function Pest\Laravel\getJson;

it('shows accepted pending multi-worker bookings in the current worker orders filter', function (): void {
    $workerUser = User::factory()->create(['email' => 'assigned-filter-worker@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $customer = User::factory()->create(['email' => 'assigned-filter-customer@example.com']);

    $acceptedPendingBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $acceptedPendingBooking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    $newUnacceptedBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $otherWorkerBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $otherWorkerBooking->id,
        'worker_id' => $otherWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    Sanctum::actingAs($workerUser);

    $response = getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[assignedToCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)
        ->toContain($acceptedPendingBooking->id)
        ->not->toContain($newUnacceptedBooking->id)
        ->not->toContain($otherWorkerBooking->id);
});

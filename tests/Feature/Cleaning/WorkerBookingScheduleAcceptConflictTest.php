<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-20 08:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rejects accepting a cleaning booking that overlaps a confirmed worker booking', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
    ]);

    Sanctum::actingAs($workerUser);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '09:00',
        'total_hours' => 3,
        'estimated_hours' => 3,
        'gender_preference' => 'any',
    ]);

    $overlappingBooking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '11:00',
        'total_hours' => 2,
        'estimated_hours' => 2,
        'gender_preference' => 'any',
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$overlappingBooking->id}/accept");

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['schedule']);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $overlappingBooking->id,
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
    ]);
});

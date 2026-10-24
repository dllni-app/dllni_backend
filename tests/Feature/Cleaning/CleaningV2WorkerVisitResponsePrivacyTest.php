<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

it('redacts sibling worker identities and session ids from session acceptance coverage', function (): void {
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => User::factory()->create(['is_active' => true])->id,
        'is_active' => true,
        'is_suspended' => false,
        'preferred_work_type' => 'cleaning',
    ]);
    $otherWorker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
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
        'status' => 'scheduled',
    ]));
    $sessions[1]->workerAssignments()->create([
        'worker_id' => $otherWorker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $sessions[0]->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    Sanctum::actingAs($worker->user);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected", [
        'sessionIds' => [$sessions[0]->id],
    ])->assertOk();

    $coverage = collect($response->json('data.coverage'))->keyBy('sequence');
    expect($coverage[1]['sessionId'])->toBe($sessions[0]->id)
        ->and($coverage[1]['workerIds'])->toBe([$worker->id])
        ->and($coverage[2]['sessionId'])->toBeNull()
        ->and($coverage[2]['workerIds'])->toBe([]);
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('keeps the worker attendance expiry timezone when reusing an unexpired proof', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $user->id]);
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'worker_id' => null,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now()->subMinutes(15),
        'arrived_at' => now()->subMinute(),
    ]);
    Sanctum::actingAs($user);

    $url = "/api/v1/cleaning-bookings/{$booking->id}/worker-attendance/code";
    $first = $this->postJson($url)->assertOk()->json('data');
    $second = $this->postJson($url)->assertOk()->json('data');

    expect($first['status'])->toBe('pending')
        ->and($second['status'])->toBe('pending')
        ->and($second['code'])->toBe($first['code'])
        ->and($first['expiresAt'])->toMatch('/T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and($second['expiresAt'])->toMatch('/T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and($second['expiresAt'])->toBe($first['expiresAt']);
});

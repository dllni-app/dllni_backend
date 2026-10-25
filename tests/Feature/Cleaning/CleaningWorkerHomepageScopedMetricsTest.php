<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('counts accepted pending team work today for its assigned worker only', function (): void {
    $viewer = User::factory()->create();
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $viewer->id]);
    $other = Worker::factory()->create();
    $accepted = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => today(),
        'number_of_workers' => 2,
    ]);
    $accepted->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => $other->id,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => today(),
    ]);
    $tomorrow = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => today()->addDay(),
    ]);
    $tomorrow->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($viewer);
    $response = $this->getJson('/api/v1/cleaning/worker/homepage')->assertOk();
    expect($response->json('totalBookings'))->toBe(2)
        ->and($response->json('todayCount'))->toBe(1)
        ->and($response->json('pendingCount'))->toBe(2);
});

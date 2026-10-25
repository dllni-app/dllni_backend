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

it('separates accepted pending team bookings from new worker offers', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    $customer = User::factory()->create();

    $accepted = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 3,
        'status' => CleaningBookingStatus::Pending->value,
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $accepted->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 500,
        'travel_fee' => 50,
        'admin_margin_amount' => 20,
        'worker_amount' => 550,
        'currency' => 'SYP',
    ]);
    $available = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker->value,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    Sanctum::actingAs($workerUser);

    $new = getJson('/api/v1/cleaning-bookings?filter[status]=pending&filter[workerView]=available')
        ->assertOk();
    $newIds = collect($new->json('data'))->pluck('id');
    expect($newIds)->toContain($available->id)->not->toContain($accepted->id);

    $upcoming = getJson('/api/v1/cleaning-bookings?filter[status]=pending,worker_assigned&filter[workerView]=mine')
        ->assertOk();
    $upcomingIds = collect($upcoming->json('data'))->pluck('id');
    expect($upcomingIds)->toContain($accepted->id)->not->toContain($available->id);
    $mine = collect($upcoming->json('data'))->firstWhere('id', $accepted->id);
    expect((float) data_get($mine, 'myAssignment.workerAmount'))->toBe(550.0);

    // A previously accepted team member must retain history access after the
    // assignment becomes terminal; a mere candidate does not receive access.
    $accepted->update(['status' => CleaningBookingStatus::Cancelled->value]);
    $assignment->update(['status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value]);
    $history = getJson('/api/v1/cleaning-bookings?filter[status]=cancelled&filter[workerView]=mine')
        ->assertOk();
    expect(collect($history->json('data'))->pluck('id'))->toContain($accepted->id);
});

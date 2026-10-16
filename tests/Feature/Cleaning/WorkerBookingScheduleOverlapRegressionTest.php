<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\WorkerBookingScheduleConflictService;

it('offers a worker another booking later the same day when times do not overlap', function (): void {
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $day = now()->addDays(3)->toDateString();

    $accepted = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => $day,
        'scheduled_time' => '09:00',
        'total_hours' => 1,
        'estimated_hours' => 1,
    ]);
    $later = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'scheduled_date' => $day,
        'scheduled_time' => '12:00',
        'total_hours' => 1,
        'estimated_hours' => 1,
    ]);
    $overlapping = CleaningBooking::factory()->create([
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending->value,
        'scheduled_date' => $day,
        'scheduled_time' => '09:30',
        'total_hours' => 1,
        'estimated_hours' => 1,
    ]);

    $conflicts = app(WorkerBookingScheduleConflictService::class);
    expect($conflicts->hasConflict($worker, $later))->toBeFalse();
    expect($conflicts->hasConflict($worker, $overlapping))->toBeTrue();
});

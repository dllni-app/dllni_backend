<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingTeamService;

it('releases one team worker without cancelling the booking or the other worker', function (): void {
    $customer = User::factory()->create();
    $first = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $second = Worker::factory()->create(['user_id' => User::factory()->create()->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $first->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'number_of_workers' => 2,
        'property_type' => 'event_assistance',
        'base_price' => 4000,
        'addons_total' => 0,
    ]);

    foreach ([$first, $second] as $worker) {
        $booking->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 2000,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 2000,
            'currency' => 'SYP',
        ]);
    }

    app(CleaningBookingTeamService::class)->rejectWorker(
        $booking, $first, 'تبديل العامل من قبل الإدارة', false,
    );

    $booking->refresh();
    expect($booking->status)->toBe(CleaningBookingStatus::Pending)
        ->and($booking->cancelled_at)->toBeNull()
        ->and($booking->workerAssignments()->where('worker_id', $second->id)->firstOrFail()->status)
        ->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($booking->remainingWorkerCount())->toBe(1);
});

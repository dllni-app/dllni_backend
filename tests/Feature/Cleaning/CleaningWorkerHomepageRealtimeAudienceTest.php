<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Events\CleaningBookingTeamUpdated;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;

it('broadcasts team and tracking lifecycle updates to workers that received the pending order', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $unrelatedWorker = Worker::factory()->create();

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
    ]);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => NewOrderRequestNotification::class,
        'notifiable_type' => $workerUser->getMorphClass(),
        'notifiable_id' => $workerUser->id,
        'data' => json_encode([
            'bookingId' => (int) $booking->id,
            'orderId' => (int) $booking->id,
        ], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $teamChannels = collect((new CleaningBookingTeamUpdated(
        cleaningBookingId: (int) $booking->id,
        team: [
            'cleaningBookingId' => (int) $booking->id,
            'status' => 'worker_assigned',
            'isFulfilled' => true,
        ],
    ))->broadcastOn())->pluck('name');

    $trackingChannels = collect((new CleaningBookingTrackingUpdated(
        cleaningBookingId: (int) $booking->id,
        tracking: [
            'cleaningBookingId' => (int) $booking->id,
            'status' => 'cancelled',
        ],
    ))->broadcastOn())->pluck('name');

    expect($teamChannels)
        ->toContain('private-cleaning-booking.' . $booking->id)
        ->toContain('private-cleaning-worker.' . $worker->id)
        ->not->toContain('private-cleaning-worker.' . $unrelatedWorker->id);

    expect($trackingChannels)
        ->toContain('private-cleaning-booking.' . $booking->id)
        ->toContain('private-cleaning-worker.' . $worker->id)
        ->not->toContain('private-cleaning-worker.' . $unrelatedWorker->id);
});

it('keeps assigned workers in the realtime audience even without a new-order notification row', function (): void {
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'preferred_worker_id' => null,
    ]);

    $channels = collect((new CleaningBookingTrackingUpdated(
        cleaningBookingId: (int) $booking->id,
        tracking: [
            'cleaningBookingId' => (int) $booking->id,
            'status' => 'cancelled',
        ],
    ))->broadcastOn())->pluck('name');

    expect($channels)
        ->toContain('private-cleaning-booking.' . $booking->id)
        ->toContain('private-cleaning-worker.' . $worker->id);
});

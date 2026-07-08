<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingCreated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;

it('does not notify workers who are outside the booking working hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00'));
    Notification::fake();
    Event::fake([CleaningBookingCreated::class]);

    try {
        $bookingDate = Carbon::now()->toDateString();
        $dayKey = mb_strtolower(Carbon::now()->format('l'));
        $neighborhood = CleaningNeighborhood::factory()->create();

        $outsideUser = \App\Models\User::factory()->create(['email' => 'worker-outside-hours@example.com']);
        $outsideWorker = Worker::factory()->create([
            'user_id' => $outsideUser->id,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['09:00' => '11:00']]],
            ],
        ]);
        $outsideWorker->zones()->create([
            'name' => 'Zone A',
            'neighborhood_id' => $neighborhood->id,
            'is_active' => true,
        ]);

        $insideUser = \App\Models\User::factory()->create(['email' => 'worker-inside-hours@example.com']);
        $insideWorker = Worker::factory()->create([
            'user_id' => $insideUser->id,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['14:00' => '18:00']]],
            ],
        ]);
        $insideWorker->zones()->create([
            'name' => 'Zone B',
            'neighborhood_id' => $neighborhood->id,
            'is_active' => true,
        ]);

        $booking = CleaningBooking::factory()->create([
            'worker_id' => null,
            'status' => CleaningBookingStatus::Pending->value,
            'gender_preference' => 'any',
            'scheduled_date' => $bookingDate,
            'scheduled_time' => '15:00',
            'neighborhood_id' => $neighborhood->id,
            'neighborhood_name' => (string) $neighborhood->name_ar,
            'address_latitude' => 36.2100,
            'address_longitude' => 37.1600,
        ]);

        (new NotifyEligibleWorkersNewOrderJob($booking->id))->handle();

        Notification::assertSentTo($insideUser, NewOrderRequestNotification::class);
        Notification::assertNotSentTo($outsideUser, NewOrderRequestNotification::class);
        Event::assertDispatched(CleaningBookingCreated::class, function (CleaningBookingCreated $event) use ($booking, $insideWorker): bool {
            return $event->cleaningBookingId === (int) $booking->id
                && $event->workerId === (int) $insideWorker->id;
        });
    } finally {
        Carbon::setTestNow();
    }
});

<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('does not notify workers who are outside the booking working hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00'));
    Notification::fake();

    try {
        $bookingDate = Carbon::now()->toDateString();
        $dayKey = mb_strtolower(Carbon::now()->format('l'));

        $outsideUser = \App\Models\User::factory()->create(['email' => 'worker-outside-hours@example.com']);
        $outsideWorker = Worker::factory()->create([
            'user_id' => $outsideUser->id,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['09:00' => '11:00']]],
            ],
        ]);
        $outsideWorker->zones()->create(['name' => 'Zone A', 'is_active' => true]);

        $insideUser = \App\Models\User::factory()->create(['email' => 'worker-inside-hours@example.com']);
        $insideWorker = Worker::factory()->create([
            'user_id' => $insideUser->id,
            'default_working_hours' => [
                $dayKey => ['available' => true, 'data' => [['14:00' => '18:00']]],
            ],
        ]);
        $insideWorker->zones()->create(['name' => 'Zone B', 'is_active' => true]);

        $booking = CleaningBooking::factory()->create([
            'worker_id' => null,
            'status' => CleaningBookingStatus::Pending->value,
            'gender_preference' => 'any',
            'scheduled_date' => $bookingDate,
            'scheduled_time' => '15:00',
        ]);

        (new NotifyEligibleWorkersNewOrderJob($booking->id))->handle();

        Notification::assertSentTo($insideUser, NewOrderRequestNotification::class);
        Notification::assertNotSentTo($outsideUser, NewOrderRequestNotification::class);
    } finally {
        Carbon::setTestNow();
    }
});

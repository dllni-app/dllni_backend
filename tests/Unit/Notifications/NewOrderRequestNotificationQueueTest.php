<?php

declare(strict_types=1);

use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Cleaning\Models\CleaningBooking;

it('does not force cleaning new-order notifications onto the push queue', function (): void {
    $booking = CleaningBooking::factory()->create();

    $notification = new NewOrderRequestNotification($booking);

    expect($notification)->not->toBeInstanceOf(ShouldQueue::class);
});

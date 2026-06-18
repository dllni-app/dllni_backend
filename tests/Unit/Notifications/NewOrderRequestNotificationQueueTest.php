<?php

declare(strict_types=1);

use App\Notifications\Cleaning\NewOrderRequestNotification;
use Modules\Cleaning\Models\CleaningBooking;

it('does not force cleaning new-order notifications onto the push queue', function (): void {
    $booking = CleaningBooking::factory()->create();

    $notification = new NewOrderRequestNotification($booking);

    expect($notification->queue)->toBeNull();
});

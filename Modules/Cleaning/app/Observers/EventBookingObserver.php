<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Models\BookingStatusLog;
use Modules\Cleaning\Models\EventBooking;

final class EventBookingObserver
{
    public function created(EventBooking $booking): void
    {
        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'booking_type' => EventBooking::class,
            'from_status' => null,
            'to_status' => $booking->status->value,
        ]);
    }

    public function updated(EventBooking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'booking_type' => EventBooking::class,
            'from_status' => (string) $booking->getOriginal('status'),
            'to_status' => $booking->status->value,
        ]);
    }
}

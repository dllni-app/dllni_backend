<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Models\BookingStatusLog;
use BackedEnum;
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

        $fromStatus = $booking->getOriginal('status');

        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'booking_type' => EventBooking::class,
            'from_status' => $fromStatus instanceof BackedEnum ? $fromStatus->value : (string) $fromStatus,
            'to_status' => $booking->status->value,
        ]);
    }
}

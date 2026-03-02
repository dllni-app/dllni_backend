<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\BookingStatusLog;
use BackedEnum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingObserver
{
    public function created(CleaningBooking $booking): void
    {
        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'booking_type' => CleaningBooking::class,
            'from_status' => null,
            'to_status' => $booking->status->value,
        ]);

        if ($booking->status !== CleaningBookingStatus::Pending) {
            return;
        }

        NotifyEligibleWorkersNewOrderJob::dispatch($booking->id);
    }

    public function updated(CleaningBooking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        $fromStatus = $booking->getOriginal('status');

        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'booking_type' => CleaningBooking::class,
            'from_status' => $fromStatus instanceof BackedEnum ? $fromStatus->value : (string) $fromStatus,
            'to_status' => $booking->status->value,
        ]);
    }
}

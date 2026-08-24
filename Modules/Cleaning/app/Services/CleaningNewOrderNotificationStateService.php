<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningNewOrderNotificationStateService
{
    public function closeForFulfilledBooking(CleaningBooking $booking): void
    {
        if (! $booking->isTeamFulfilled()) {
            return;
        }

        DatabaseNotification::query()
            ->where('type', NewOrderRequestNotification::class)
            ->where(function (Builder $query) use ($booking): void {
                $bookingId = (int) $booking->id;

                $query->where('data->bookingId', $bookingId)
                    ->orWhere('data->orderId', $bookingId)
                    ->orWhere('data->data->bookingId', $bookingId)
                    ->orWhere('data->data->orderId', $bookingId);
            })
            ->delete();
    }
}

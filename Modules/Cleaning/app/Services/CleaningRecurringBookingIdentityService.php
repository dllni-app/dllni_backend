<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * Read-only historical V2/V3 multi-visit identity.
 *
 * V2 creates one Booking per appointment and stores their common quote link
 * in cleaning_quote_bookings. V3 retains one Booking with multiple sessions.
 * This never consolidates, cancels, re-prices or mutates historical bookings.
 */
final class CleaningRecurringBookingIdentityService
{
    public function isRecurringOrMultiVisit(CleaningBooking $booking): bool
    {
        if ((string) ($booking->contract_version ?? '') === 'v3') {
            return $booking->sessions()->count() > 1;
        }

        // Legacy V2 group: each appointment gets its own Booking id, but all
        // appointments share the original quote. A single booking by itself
        // is NOT a recurring plan.
        $quoteId = DB::table('cleaning_quote_bookings')
            ->where('cleaning_booking_id', $booking->id)
            ->value('quote_id');

        if ($quoteId !== null) {
            return DB::table('cleaning_quote_bookings')
                ->where('quote_id', $quoteId)
                ->limit(2)
                ->count() > 1;
        }

        // Historical V1 time-based recurring schedule retained as metadata.
        $details = is_array($booking->property_details) ? $booking->property_details : [];

        return data_get($details, 'schedule.mode') === 'recurring'
            || (is_array(data_get($details, 'recurring_schedule'))
                && count(data_get($details, 'recurring_schedule')) > 1);
    }
}

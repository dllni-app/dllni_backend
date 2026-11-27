<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Resolve the accepted immutable commercial quote under the existing booking
 * transaction. A shared quote is never authority for a sibling execution.
 */
final class CleaningV3AcceptedQuoteResolver
{
    /** @return array{0: object, 1: array, 2: ?int} quote, signed snapshot, daily sequence */
    public function load(CleaningBooking $booking, string $error): array
    {
        if (DB::transactionLevel() < 1 || $booking->contract_version !== 'v3') {
            throw new DomainException($error);
        }

        $currentId = data_get($booking->property_details, 'v3_current_quote_id');
        $originId = data_get($booking->property_details, 'v3_daily_origin_quote_id');
        $link = null;
        $query = DB::table('cleaning_booking_quotes')->where('contract_version', 'v3');
        if ($originId !== null) {
            if (! is_string($originId) || ! is_string($currentId)) {
                throw new DomainException($error);
            }
            $link = DB::table('cleaning_quote_bookings')->where('cleaning_booking_id', $booking->id)
                ->where('quote_id', $originId)->lockForUpdate()->first();
            if ($link === null || (int) $link->appointment_sequence < 1) {
                throw new DomainException($error);
            }
            $query->where('id', $currentId);
            if ($currentId !== $originId) {
                // A later, independently signed amendment belongs only to
                // this booking. The origin link remains immutable for replay.
                $query->where('cleaning_booking_id', $booking->id);
            }
        } else {
            // Historical single/multi-visit V3 bookings keep the original
            // anchor lookup. Never treat another booking's quote as theirs.
            $query->where('cleaning_booking_id', $booking->id);
            if (is_string($currentId)) {
                $query->where('id', $currentId);
            }
        }

        $quotes = $query->lockForUpdate()->get();
        $catalog = CleaningV3CatalogVersion::query()->whereKey($booking->catalog_version_id)->first();
        if ($quotes->count() !== 1 || $catalog === null || $catalog->status !== 'published' || $catalog->published_at === null
            || ! hash_equals((string) $catalog->published_digest, app(CleaningV3CatalogReviewManifestService::class)->fingerprint($catalog))) {
            throw new DomainException($error);
        }
        $quote = $quotes->first();
        $envelope = json_decode((string) $quote->snapshot, true);
        if ($quote->cleaning_booking_id === null
            || ! is_array($envelope) || ($envelope['quoteId'] ?? null) !== $quote->id
            || ! is_string($envelope['signature'] ?? null) || ! hash_equals((string) $quote->signature, $envelope['signature'])
            || (int) $quote->customer_id !== (int) $booking->customer_id || (int) $quote->catalog_version_id !== (int) $catalog->id) {
            throw new DomainException($error);
        }
        $accepted = data_get($booking->property_details, 'v3_quote_accepted_at');
        $at = is_string($accepted) ? CarbonImmutable::parse($accepted)->utc() : CarbonImmutable::instance($booking->created_at)->utc();
        $snapshot = app(CleaningV3SignedQuoteEnvelope::class)->verify($envelope, (int) $booking->customer_id,
            $catalog->version_key, $catalog->published_digest, $at);
        if (($snapshot['paymentMethod'] ?? null) !== 'cash_on_delivery' || ($snapshot['paymentStatus'] ?? null) !== 'unpaid') {
            throw new DomainException($error);
        }

        $sequence = null;
        if (array_key_exists('dailyAppointments', $snapshot)) {
            if ($link === null || $originId !== $currentId) {
                throw new DomainException($error);
            }
            $sequence = (int) $link->appointment_sequence;
            $anchor = DB::table('cleaning_quote_bookings')->where('quote_id', $originId)
                ->where('appointment_sequence', 1)->value('cleaning_booking_id');
            if ((int) $anchor !== (int) $quote->cleaning_booking_id) {
                throw new DomainException($error);
            }
            $visits = $booking->sessions()->where('status', '!=', 'superseded')->lockForUpdate()->get();
            if ($visits->count() !== 1) {
                throw new DomainException($error);
            }
            app(CleaningV3DailyQuoteScope::class)->assertExecution($snapshot, $sequence, $visits->first());
        } elseif ($link !== null) {
            if ($originId === $currentId
                || ($snapshot['amendment']['bookingId'] ?? null) !== (int) $booking->id
                || count($snapshot['visits']) !== 1) {
                throw new DomainException($error);
            }
        }

        return [$quote, $snapshot, $sequence];
    }
}

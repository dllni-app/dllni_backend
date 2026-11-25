<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/** Load the original customer-approved signed commercial price under a visit lock. */
final class CleaningV3ConfirmedVisitPrice
{
    public function load(CleaningBooking $booking, CleaningBookingSession $visit): array
    {
        if (DB::transactionLevel() < 1 || $booking->contract_version !== 'v3'
            || (int) $visit->cleaning_booking_id !== (int) $booking->id) {
            throw new DomainException('V3_VISIT_PRICE_TRANSACTION_REQUIRED');
        }
        $query = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)
            ->where('contract_version', 'v3');
        if (is_string($current = data_get($booking->property_details, 'v3_current_quote_id'))) {
            $query->where('id', $current);
        }
        $quotes = $query->lockForUpdate()->get();
        $catalog = CleaningV3CatalogVersion::query()->whereKey($booking->catalog_version_id)->first();
        if ($quotes->count() !== 1 || $catalog === null || $catalog->status !== 'published' || $catalog->published_at === null
            || ! hash_equals((string) $catalog->published_digest, app(CleaningV3CatalogReviewManifestService::class)->fingerprint($catalog))) {
            throw new DomainException('V3_CONFIRMED_QUOTE_REQUIRED');
        }
        $quote = $quotes->first();
        $envelope = json_decode((string) $quote->snapshot, true);
        if (! is_array($envelope) || ($envelope['quoteId'] ?? null) !== $quote->id
            || ! is_string($envelope['signature'] ?? null) || ! hash_equals((string) $quote->signature, $envelope['signature'])
            || (int) $quote->customer_id !== (int) $booking->customer_id || (int) $quote->catalog_version_id !== (int) $catalog->id) {
            throw new DomainException('V3_CONFIRMED_QUOTE_REQUIRED');
        }
        $accepted = data_get($booking->property_details, 'v3_quote_accepted_at');
        $at = is_string($accepted) ? CarbonImmutable::parse($accepted)->utc() : CarbonImmutable::instance($booking->created_at)->utc();
        $snapshot = app(CleaningV3SignedQuoteEnvelope::class)->verify($envelope, (int) $booking->customer_id,
            $catalog->version_key, $catalog->published_digest, $at);
        if (($snapshot['paymentMethod'] ?? null) !== 'cash_on_delivery'
            || ($snapshot['paymentStatus'] ?? null) !== 'unpaid') {
            throw new DomainException('V3_CONFIRMED_QUOTE_REQUIRED');
        }
        $key = $visit->occurrence_key;
        $row = collect($snapshot['commercialReview']['visits'] ?? [])->firstWhere('occurrenceKey', $key);
        $basis = $snapshot['settlementBasis']['visits'][$key] ?? null;
        if ($row === null || $basis === null || $row['appointmentKey'] !== $visit->appointment_key
            || $row['pricedWorkers'] !== $visit->requiredWorkerCount()) {
            throw new DomainException('V3_CONFIRMED_VISIT_PRICE_MISMATCH');
        }
        $revision = $snapshot['pricingRevision'];
        return [$quote, $snapshot, $row, $basis, $revision];
    }

    public function assertPersisted(CleaningBookingSession $visit, array $row, array $basis, int $revision): void
    {
        if (! $visit->is_pricing_final || $revision !== (int) $visit->price_revision
            || bccomp($row['totalAmountSyp'], (string) $visit->getRawOriginal('total_price'), 2) !== 0
            || (int) round((float) $visit->duration_hours * 60) !== $basis['plannedMinutes']
            || bccomp((string) ($visit->getRawOriginal('extension_fee_total') ?? 0), '0', 2) !== 0) {
            throw new DomainException('V3_CONFIRMED_VISIT_PRICE_MISMATCH');
        }
    }

    public function obligations(array $snapshot, array $row, array $basis): array
    {
        $cents = CleaningV3CashSettlementBasis::cents(...);
        $money = CleaningV3CashSettlementBasis::money(...);
        $worker = $cents($row['baseAmountSyp']) + $cents($row['addonsAmountSyp'])
            - $cents($basis['materialAmountSyp']) + $cents($row['travelAmountSyp']);
        $supervisor = $basis['billingMode'] === 'task' && $row['pricedWorkers'] > 1
            ? (int) bcdiv(bcadd(bcmul((string) $cents($snapshot['settlementBasis']['supervisorHourlyCompensation']),
                (string) $basis['plannedMinutes'], 0), '59', 0), '60', 0) : 0;
        return ['workerObligationSyp' => $money($worker), 'supervisorObligationSyp' => $money($supervisor),
            'requiredFundingSyp' => $money($worker + $supervisor)];
    }
}

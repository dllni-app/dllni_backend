<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Pre-dispatch amendment only. The old quote remains linked for audit.
 * A held booking can change its approved workforce/duration without acquiring
 * another booking number. Live-work amendments need operational admission.
 */
final class CleaningV3PendingRevisionService
{
    public function lock(int $customerId, int $bookingId, int $expectedRevision): array
    {
        if (DB::transactionLevel() < 1 || $expectedRevision < 1 || $expectedRevision >= 2147483647) {
            throw new DomainException('INVALID_V3_PRICE_REVISION');
        }
        $booking = CleaningBooking::query()->whereKey($bookingId)->where('customer_id', $customerId)->lockForUpdate()->first();
        if ($booking === null || $booking->contract_version !== 'v3'
            || $booking->status->value !== 'pending'
            || data_get($booking->property_details, 'v3_admission_hold') !== true) {
            throw new DomainException('V3_PENDING_REVISION_UNAVAILABLE');
        }
        // Existing coupon reservations and equipment custody require a
        // replacement-aware allocation workflow. Never release them on preview.
        if ($booking->platform_coupon_id !== null
            || $booking->supervisorAssignments()->exists() || $booking->workerAssignments()->exists()
            || DB::table('cleaning_equipment_reservations')->where('cleaning_booking_id', $bookingId)->where('status', '!=', 'released')->exists()) {
            throw new DomainException('V3_REVISION_RESOURCES_REVIEW_REQUIRED');
        }
        $visits = $booking->sessions()->orderBy('id')->lockForUpdate()->get();
        $ids = $visits->pluck('id');
        // A new price cannot orphan independently reserved funds. Require
        // external reconciliation before replacing an already funded quote.
        if (DB::table('cleaning_visit_funding_reservations')->whereIn('active_visit_id', $ids)->exists()) {
            throw new DomainException('V3_REVISION_FUNDING_RECONCILIATION_REQUIRED');
        }
        if ($visits->isEmpty() || $visits->contains(fn ($v) => $v->status->value !== 'scheduled'
            || (int) $v->price_revision !== $expectedRevision || $v->workerAssignments()->exists())
            || DB::table('cleaning_v3_visit_dispatches')->whereIn('cleaning_booking_session_id', $ids)->exists()
            || DB::table('cleaning_work_intervals')->whereIn('cleaning_booking_session_id', $ids)->exists()
            || DB::table('cleaning_v3_cash_movements')->whereIn('cleaning_booking_session_id', $ids)->exists()) {
            throw new DomainException('V3_PRICE_REVISION_FROZEN_OR_STALE');
        }
        $query = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $bookingId)->where('contract_version', 'v3');
        $currentId = data_get($booking->property_details, 'v3_current_quote_id');
        if (is_string($currentId)) {
            $query->where('id', $currentId);
        }
        $quotes = $query->lockForUpdate()->get();
        if ($quotes->count() !== 1) {
            throw new DomainException('V3_REVISION_QUOTE_LINK_REQUIRED');
        }
        $quote = $quotes->first();
        $catalog = CleaningV3CatalogVersion::query()->whereKey($booking->catalog_version_id)->firstOrFail();
        $envelope = json_decode((string) $quote->snapshot, true);
        if (! is_array($envelope) || ($envelope['quoteId'] ?? null) !== $quote->id
            || ! hash_equals((string) $quote->signature, (string) ($envelope['signature'] ?? ''))
            || (int) $quote->customer_id !== $customerId || (int) $quote->catalog_version_id !== (int) $catalog->id) {
            throw new DomainException('V3_REVISION_QUOTE_LINK_REQUIRED');
        }
        $accepted = data_get($booking->property_details, 'v3_quote_accepted_at');
        $at = is_string($accepted) ? CarbonImmutable::parse($accepted)->utc() : CarbonImmutable::instance($booking->created_at)->utc();
        $snapshot = app(CleaningV3SignedQuoteEnvelope::class)->verify($envelope, $customerId, $catalog->version_key, $catalog->published_digest, $at);
        if ($snapshot['pricingRevision'] !== $expectedRevision || ($snapshot['paymentStatus'] ?? null) !== 'unpaid'
            || count($snapshot['visits']) !== $visits->count()) {
            throw new DomainException('V3_PRICE_REVISION_FROZEN_OR_STALE');
        }
        return [$booking, $visits, $quote];
    }

    public function assertSameVisits($visits, array $plan, array $review): void
    {
        if (($review['couponCode'] ?? null) !== null
            || ($review['totals']['equipmentAmountSyp'] ?? null) !== '0.00') {
            throw new DomainException('V3_REVISION_RESOURCES_REVIEW_REQUIRED');
        }
        $old = $visits->keyBy('occurrence_key');
        $count = 0;
        foreach ($plan['appointments'] as $appointment) {
            foreach ($appointment['visits'] as $visit) {
                $prior = $old->get($visit['occurrenceKey']);
                if ($prior === null || $prior->appointment_key !== $appointment['appointmentKey']) {
                    throw new DomainException('V3_REVISION_VISIT_IDENTITY_CHANGED');
                }
                $count++;
            }
        }
        if ($count !== $visits->count()) {
            throw new DomainException('V3_REVISION_VISIT_IDENTITY_CHANGED');
        }
    }
}

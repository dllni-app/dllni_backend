<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;

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
        [$quote, $snapshot, $sequence] = app(CleaningV3AcceptedQuoteResolver::class)
            ->load($booking, 'V3_REVISION_QUOTE_LINK_REQUIRED');
        if ($sequence !== null) {
            app(CleaningV3DailyQuoteScope::class)->assertExecution($snapshot, $sequence, $visits->first(), true);
        }
        if ($snapshot['pricingRevision'] !== $expectedRevision || ($snapshot['paymentStatus'] ?? null) !== 'unpaid'
            || ($sequence === null ? count($snapshot['visits']) : 1) !== $visits->count()) {
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

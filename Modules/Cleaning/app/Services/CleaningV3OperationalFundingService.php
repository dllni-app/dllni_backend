<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Exact funding request for an independent cash custodian; no balance creation by admins. */
final class CleaningV3OperationalFundingService
{
    public function requirement(CleaningBooking $booking, CleaningBookingSession $visit): array
    {
        [$quote, $snapshot, $row, $basis, $revision] = app(CleaningV3LiveExtensionService::class)->effectivePrice($booking, $visit);
        app(CleaningV3ConfirmedVisitPrice::class)->assertPersisted($visit, $row, $basis, $revision);
        $obligations = app(CleaningV3ConfirmedVisitPrice::class)->obligations($snapshot, $row, $basis);
        $digest = app(CleaningV3LiveExtensionPricing::class)->digest($quote->id, (int) $booking->id, (int) $visit->id, $revision, $row, $basis);
        $verified = false;
        $reason = null;
        $cashPolicy = app(CleaningV3SupervisorCashPolicy::class);
        if ($cashPolicy->applies($basis)) {
            $payable = false;
            try {
                $cashPolicy->assertPayable($booking, $visit, $row, $basis, $obligations);
                $payable = true;
            } catch (DomainException $e) {
                $reason = $e->getMessage();
            }
            return ['bookingId' => (int) $booking->id, 'visitId' => (int) $visit->id, 'quoteId' => $quote->id,
                'pricingRevision' => $revision, 'pricingDigest' => $digest, 'currency' => 'SYP', ...$obligations,
                'collectionMode' => 'supervisor_manual', 'verified' => false, 'cashPricePayable' => $payable,
                'blocker' => $reason];
        }
        try {
            app(CleaningV3VerifiedTreasuryReservationService::class)->assertVerified($booking, $visit, $quote->id, $revision, $digest, $obligations);
            $verified = true;
        } catch (DomainException $e) {
            $reason = $e->getMessage();
        }
        return ['bookingId' => (int) $booking->id, 'visitId' => (int) $visit->id, 'quoteId' => $quote->id,
            'pricingRevision' => $revision, 'pricingDigest' => $digest, 'currency' => 'SYP',
            ...$obligations, 'verified' => $verified, 'blocker' => $reason];
    }

    public function review(int $bookingId, int $visitId): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        return DB::transaction(function () use ($bookingId, $visitId) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            return $this->requirement($booking, $visit);
        }, 3);
    }

    public function import(int $bookingId, int $visitId, array $proof): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        return DB::transaction(function () use ($bookingId, $visitId, $proof) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            if ($visit->isTerminal() || $visit->payment_status === 'settled') {
                throw new DomainException('V3_TREASURY_VISIT_NOT_ACTIVE');
            }
            $request = $this->requirement($booking, $visit);
            if (($request['collectionMode'] ?? null) === 'supervisor_manual') {
                throw new DomainException('V3_SUPERVISOR_CASH_EXTERNAL_FUNDING_NOT_REQUIRED');
            }
            $obligations = array_intersect_key($request, array_flip(['workerObligationSyp', 'supervisorObligationSyp', 'requiredFundingSyp']));
            app(CleaningV3VerifiedTreasuryReservationService::class)->reserve($booking, $visit, $request['quoteId'],
                $request['pricingRevision'], $request['pricingDigest'], $obligations, $proof, (int) auth()->id());
            return $this->requirement($booking, $visit);
        }, 3);
    }

    private function lock(int $bookingId, int $visitId): array
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        abort_unless($booking->contract_version === 'v3', 404);
        $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        $visit->setRelation('booking', $booking);
        return [$booking, $visit];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Cash on delivery authorizes an unpaid commercial obligation, not cleared
 * money. The protected worker fee is not available for funding ordinary wages.
 */
final class CleaningV3SupervisorCashPolicy
{
    public function applies(array $basis): bool
    {
        return ($basis['commissionAdded'] ?? false) === true;
    }

    public function assertPayable(CleaningBooking $booking, CleaningBookingSession $visit, array $row, array $basis, array $obligations): void
    {
        if (! $this->applies($basis) || $visit->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $booking->contract_version !== 'v3' || (int) $visit->cleaning_booking_id !== (int) $booking->id
            || DB::transactionLevel() < 1) {
            throw new DomainException('V3_SUPERVISOR_CASH_POLICY_REQUIRED');
        }
        $ordinary = bcsub($row['totalAmountSyp'], $row['additionalWorkerFeeSyp'], 2);
        if (bccomp($ordinary, $obligations['requiredFundingSyp'], 2) < 0) {
            throw new DomainException('V3_SUPERVISOR_CASH_PRICE_SHORTFALL');
        }
        // Do not mix old external cash allocations with the new policy.
        if (DB::table('cleaning_visit_funding_reservations')->where('active_visit_id', $visit->id)->exists()) {
            throw new DomainException('V3_TREASURY_EXISTING_RESERVATION_RECONCILIATION_REQUIRED');
        }
    }
}

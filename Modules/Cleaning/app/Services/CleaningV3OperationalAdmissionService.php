<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Atomic transition from a confirmed unpaid order to visit-scoped discovery. */
final class CleaningV3OperationalAdmissionService
{
    public static function admitted(CleaningBooking $booking): bool
    {
        return $booking->contract_version === 'v3'
            && app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking);
    }

    public function admit(int $bookingId): array
    {
        // The compatibility entry point must never create a second approval
        // or manufacture an approving actor for a scheduled command.
        if (! CleaningV3CashSettlementService::authorized()) {
            return ['admitted' => false, 'replayed' => false, 'blockers' => ['V3_OPERATIONAL_APPROVAL_REQUIRES_ACTOR']];
        }
        $result = app(CleaningV3OperationalApprovalService::class)->approve($bookingId);
        return [...$result, 'admitted' => $result['approved']];
    }

    /** Revalidate money and signed price at acceptance and before work, never from client data. */
    public function assertVisitPayable(CleaningBooking $booking, CleaningBookingSession $visit): void
    {
        if (! self::admitted($booking) || ! app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking, $visit)
            || ! DB::table('cleaning_v3_visit_dispatches')->where('cleaning_booking_session_id', $visit->id)->exists()) {
            throw new DomainException('V3_OPERATIONAL_ADMISSION_REQUIRED');
        }
        $request = app(CleaningV3OperationalFundingService::class)->requirement($booking, $visit);
        if (! $request['verified'] && ! ($request['cashPricePayable'] ?? false)) {
            throw new DomainException($request['blocker'] ?? 'V3_FINANCIAL_APPROVAL_REQUIRED');
        }
    }
}

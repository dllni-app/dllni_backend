<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * Read-only reconciliation warning for V2 bookings.
 *
 * Does not authorize charging a customer or treating an unexplained deficit
 * as an approved platform subsidy. Never updates amounts or assignments.
 */
final class CleaningBookingFinancialExposureService
{
    /**
     * @return array{customerTotal:float,workerPayable:float,adminMargin:float,supervisorFee:float,gap:float,source:string}|null
     */
    public function forBooking(CleaningBooking $booking): ?array
    {
        if (! is_array(data_get($booking->property_details, '_time_period_v2'))) {
            return null;
        }

        $accepted = CleaningBookingWorkerAssignmentStatus::acceptedValues();
        $parents = $booking->workerAssignments()->whereIn('status', $accepted);
        $parentCount = (clone $parents)->count();

        if ($parentCount > 0) {
            $payable = (float) $parents->sum('worker_amount');
            $source = 'booking_assignments';
        } else {
            // Some V2 bookings have only session assignments. Never add both
            // sources, because their amounts can represent the same work.
            $payable = (float) $booking->sessions()
                ->join('cleaning_booking_session_worker_assignments as swa', 'swa.cleaning_booking_session_id', '=', 'cleaning_booking_sessions.id')
                ->whereIn('swa.status', $accepted)
                ->sum('swa.worker_amount');
            $source = 'session_assignments';
        }

        $customerTotal = round(max(0.0, (float) $booking->total_price), 2);
        $workerPayable = round(max(0.0, $payable), 2);
        $adminMargin = round(max(0.0, (float) $booking->admin_margin_amount), 2);
        // The customer quotation earmarks this fee for supervising the team.
        // It is NOT an employee payout confirmation or an extra charge. A
        // legacy V2 booking may have allocated the entire service price to
        // workers despite retaining this quoted supervisor fee in its JSON.
        // Reconcile the money reserved for all obligations without changing
        // a historical bill or retroactively reducing accepted worker offers.
        $supervisorFee = round(max(0.0, (float) data_get(
            $booking->property_details,
            '_supervisor_pricing.fee',
            0,
        )), 2);

        return [
            'customerTotal' => $customerTotal,
            'workerPayable' => $workerPayable,
            'adminMargin' => $adminMargin,
            'supervisorFee' => $supervisorFee,
            'gap' => round(max(0.0, $workerPayable + $adminMargin + $supervisorFee - $customerTotal), 2),
            'source' => $source,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningOpenTimeBillingService;

final class CleaningAgentBOperationalObserver
{
    public function __construct(
        private readonly CleaningMaterialInventoryService $materialInventory,
        private readonly CleaningOpenTimeBillingService $openTimeBilling,
    ) {}

    /**
     * Prepare authoritative Open-Time financials before the terminal status save.
     *
     * CleaningBookingObserver debits the administration commission from its
     * `updated` hook when a booking becomes completed. Preparing the final amount,
     * assignment shares, admin margin, and coupon allocation here guarantees that
     * observer can only see final values; correctness no longer depends on the
     * registration order of `updated` observers.
     */
    public function updating(CleaningBooking $booking): void
    {
        if (! $booking->isDirty(['status', 'work_finished_at'])) {
            return;
        }

        if (
            in_array($booking->status, [
                CleaningBookingStatus::AwaitingCustomerCompletion,
                CleaningBookingStatus::Completed,
                CleaningBookingStatus::UnderDispute,
            ], true)
            && $booking->booking_kind === 'open_time'
            && $booking->open_time_finalized_at === null
            && $booking->work_finished_at !== null
        ) {
            $this->openTimeBilling->prepareFinalPricing($booking, $booking->work_finished_at);
        }
    }

    public function updated(CleaningBooking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        if ($booking->status === CleaningBookingStatus::InProgress) {
            $this->materialInventory->consumeForBooking($booking);

            return;
        }

        if ($booking->status === CleaningBookingStatus::Cancelled) {
            $this->materialInventory->releaseForBooking($booking);

            return;
        }

        // Backward-compatible fallback for legacy finish paths that persisted the
        // finish timestamp without passing through the normal terminal save. The
        // normal path is finalized in `updating()` above, before settlement.
        if (
            in_array($booking->status, [
                CleaningBookingStatus::AwaitingCustomerCompletion,
                CleaningBookingStatus::Completed,
                CleaningBookingStatus::UnderDispute,
            ], true)
            && $booking->booking_kind === 'open_time'
            && $booking->open_time_finalized_at === null
            && $booking->work_finished_at !== null
        ) {
            $this->openTimeBilling->finalize($booking, $booking->work_finished_at);
        }
    }
}

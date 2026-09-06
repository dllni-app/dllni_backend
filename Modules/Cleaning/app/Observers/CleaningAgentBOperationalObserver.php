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

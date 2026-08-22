<?php

declare(strict_types=1);

namespace Modules\Cleaning\Listeners;

use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningNewOrderNotificationStateService;

final class CloseFulfilledBookingNewOrderNotifications
{
    public function __construct(
        private readonly CleaningNewOrderNotificationStateService $notificationStateService,
    ) {}

    public function handle(CleaningBooking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        if ($booking->status !== CleaningBookingStatus::WorkerAssigned) {
            return;
        }

        if (! $booking->isTeamFulfilled()) {
            return;
        }

        $this->notificationStateService->closeForFulfilledBooking($booking);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSessionPricingService;
use Modules\Cleaning\Services\CleaningBookingSessionStatusService;
use Throwable;

final class CleaningBookingSessionProjectionObserver
{
    public function saved(CleaningBooking $booking): void
    {
        if (! $booking->isEventAssistanceBooking() || ! $booking->sessions()->exists()) {
            return;
        }

        try {
            $projected = app(CleaningBookingSessionPricingService::class)
                ->syncAssignmentsAndRecalculate($booking);
            app(CleaningBookingSessionStatusService::class)->refreshParent($projected);
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        }
    }
}

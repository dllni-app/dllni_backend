<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningPreferredWorkerFallbackService
{
    public function __construct(
        private CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    public function convertToOpenIfEligible(CleaningBooking $booking): bool
    {
        return DB::transaction(function () use ($booking): bool {
            $booking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if (! $booking instanceof CleaningBooking || ! $this->isEligibleForConversion($booking)) {
                return false;
            }

            $fromStatus = $booking->status instanceof CleaningBookingStatus
                ? $booking->status->value
                : (string) $booking->status;

            $booking->forceFill([
                'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
                'converted_from_preferred_worker' => true,
                'converted_from_preferred_worker_at' => now(),
            ])->save();

            $booking = $booking->fresh(['customer']) ?? $booking;

            $this->lifecycleNotifications->notifyCustomer(
                booking: $booking,
                canonicalType: 'cleaning.booking.preferred_worker_unavailable',
                action: 'preferred_worker_fallback',
                actorRole: 'system',
                fromStatus: $fromStatus,
                templateContext: [
                    'booking_number' => $booking->booking_number,
                ],
            );

            NotifyEligibleWorkersNewOrderJob::dispatch($booking->id)->afterCommit();

            return true;
        });
    }

    private function isEligibleForConversion(CleaningBooking $booking): bool
    {
        if ($booking->status !== CleaningBookingStatus::Pending) {
            return false;
        }

        if ($booking->resolvedAssignmentMode() !== CleaningAssignmentMode::PreferredWorker->value) {
            return false;
        }

        if ($booking->preferred_worker_id === null) {
            return false;
        }

        if ((bool) ($booking->converted_from_preferred_worker ?? false)) {
            return false;
        }

        if ($booking->isTeamFulfilled()) {
            return false;
        }

        return ! $booking->acceptedWorkerAssignments()
            ->where('worker_id', (int) $booking->preferred_worker_id)
            ->exists();
    }
}

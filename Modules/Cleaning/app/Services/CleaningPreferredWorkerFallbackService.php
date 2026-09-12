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
    private const RECURRING_SESSION_TYPE = 'recurring_cleaning';

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

            $booking->forceFill([
                'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
                'preferred_worker_id' => null,
                'converted_from_preferred_worker' => true,
                'converted_from_preferred_worker_at' => now(),
            ])->save();

            $booking->rooms()->update([
                'planned_preferred_worker_id' => null,
            ]);

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

        // Recurring bookings own worker continuity per execution session. A requested
        // worker must never be silently replaced by the generic parent-booking
        // fallback that converts preferred-worker requests into the open pool.
        if ($booking->sessions()
            ->where('session_type', self::RECURRING_SESSION_TYPE)
            ->exists()) {
            return false;
        }

        if ((bool) ($booking->converted_from_preferred_worker ?? false)) {
            return false;
        }

        if ((string) ($booking->preferred_worker_rejection_decision_status ?? '') === CleaningBooking::PREFERRED_WORKER_REJECTION_DECISION_PENDING) {
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

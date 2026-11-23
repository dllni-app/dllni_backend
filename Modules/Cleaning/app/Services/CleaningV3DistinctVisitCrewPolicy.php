<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Sequential V3 visits of the SAME appointment must use distinct crews.
 *
 * Example: a 12-hour event split into 8h + 4h cannot allocate one worker
 * to both visits, even when their local time windows do not overlap.
 * Other appointments of a recurring booking remain independently assignable.
 *
 * Re-checked by the worker acceptance service while the worker financial
 * row and visit row are transactionally locked. No user-provided worker list
 * can bypass this policy.
 */
final class CleaningV3DistinctVisitCrewPolicy
{
    public function isSequentialSibling(
        CleaningBooking $booking,
        CleaningBookingSession $candidate,
        CleaningBookingSession $other,
    ): bool {
        return (string) ($booking->contract_version ?? '') === 'v3'
            && is_string($candidate->appointment_key)
            && $candidate->appointment_key !== ''
            && (int) $candidate->cleaning_booking_id === (int) $booking->id
            && (int) $other->cleaning_booking_id === (int) $booking->id
            && (int) $candidate->id !== (int) $other->id
            && (string) $other->appointment_key === (string) $candidate->appointment_key;
    }

    public function hasCommittedSibling(
        CleaningBooking $booking,
        CleaningBookingSession $candidate,
        Worker $worker,
    ): bool {
        if ((string) ($booking->contract_version ?? '') !== 'v3'
            || ! is_string($candidate->appointment_key)
            || $candidate->appointment_key === '') {
            return false;
        }

        return DB::table('cleaning_booking_session_worker_assignments as w')
            ->join('cleaning_booking_sessions as s', 's.id', '=', 'w.cleaning_booking_session_id')
            ->where('s.cleaning_booking_id', $booking->id)
            ->where('s.appointment_key', $candidate->appointment_key)
            ->where('s.id', '!=', $candidate->id)
            ->where('w.worker_id', $worker->id)
            ->whereNull('w.released_at')
            ->whereIn('w.status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
    }
}

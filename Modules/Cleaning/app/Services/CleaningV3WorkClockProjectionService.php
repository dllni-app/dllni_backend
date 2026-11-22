<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Read-only, server-clock V3 visit projection. Never trusts device timers.
 *
 * No action is exposed for opening/closing intervals or approving pauses:
 * those operations require visit-scoped actor authorization, a canonical
 * idempotency record, row locks, and verified financial admission.
 */
final class CleaningV3WorkClockProjectionService
{
    /** @return array<string, mixed> */
    public function project(CleaningBookingSession $visit, ?CarbonImmutable $now = null): array
    {
        if ((string) ($visit->booking->contract_version ?? '') !== 'v3') {
            throw new DomainException('V3_CLOCK_CONTRACT_REQUIRED');
        }

        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $cursor = null;
        $openSince = null;
        $activeSeconds = 0;
        $rows = DB::table('cleaning_work_intervals')
            ->where('cleaning_booking_session_id', $visit->id)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $started = CarbonImmutable::parse((string) $row->started_at, 'UTC');
            $ended = $row->ended_at === null
                ? null : CarbonImmutable::parse((string) $row->ended_at, 'UTC');

            if ($started > $now || ($cursor !== null && $started < $cursor)
                || ($ended !== null && ($ended <= $started || $ended > $now))
                || ($ended === null && ((int) $row->active_session_id !== (int) $visit->id))
                || ($ended !== null && $row->active_session_id !== null)
                || $openSince !== null) {
                throw new DomainException('INVALID_V3_CLOCK_INTERVAL_HISTORY');
            }

            $effectiveEnd = $ended ?? $now;
            $activeSeconds += (int) $started->diffInSeconds($effectiveEnd, false);
            $cursor = $effectiveEnd;

            if ($ended === null) {
                $openSince = $started;
            }
        }

        $pendingCount = DB::table('cleaning_work_pause_requests')
            ->where('cleaning_booking_session_id', $visit->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingCount > 1) {
            throw new DomainException('INVALID_V3_PAUSE_REQUEST_HISTORY');
        }

        $status = $visit->status?->value ?? (string) $visit->status;
        $terminal = in_array($status, CleaningBookingSessionStatus::terminalValues(), true);

        if (($terminal && $openSince !== null)
            || ($status === CleaningBookingSessionStatus::Paused->value
                && ($openSince !== null || $rows->isEmpty()))
            || ($status === CleaningBookingSessionStatus::InProgress->value && $openSince === null)
            || ($status === CleaningBookingSessionStatus::Scheduled->value && $openSince !== null)
            || ($pendingCount > 0 && ($openSince === null || $terminal))) {
            throw new DomainException('INVALID_V3_CLOCK_VISIT_STATE');
        }

        $state = match (true) {
            $terminal => $status,
            $openSince !== null => 'running',
            $status === CleaningBookingSessionStatus::Paused->value => 'paused',
            $rows->isEmpty() => 'not_started',
            default => 'not_running',
        };

        return [
            'state' => $state,
            'revision' => (int) $visit->clock_revision,
            'serverNow' => $now->toIso8601String(),
            'activeSeconds' => $activeSeconds,
            'activeSince' => $openSince?->toIso8601String(),
            'pausePending' => $pendingCount === 1,
            'visitId' => (int) $visit->id,
            'canSettle' => false,
        ];
    }
}

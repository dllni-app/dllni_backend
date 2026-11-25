<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * A dispatch/search deadline belongs to one V3 visit, never the booking
 * header or an individual offered worker. The six-hour timer is an escalation
 * threshold, not a cancellation or an offer expiry.
 */
final class CleaningV3VisitDispatchService
{
    public const THRESHOLD_MINUTES = 360;

    /**
     * Start only when the visit is actually opened for worker discovery.
     * Repeated or late invocations never reset the original deadline.
     *
     * @return array<string,mixed>
     */
    public function openSearch(CleaningBookingSession $session, ?CarbonInterface $clock = null): array
    {
        return DB::transaction(function () use ($session, $clock): array {
            $visit = $this->lockVisit($session);
            if (data_get($visit->booking->property_details, 'v3_admission_hold') === true) {
                throw new DomainException('V3_OPERATIONAL_ADMISSION_REQUIRED');
            }
            $now = $this->clock($clock);
            $existing = DB::table('cleaning_v3_visit_dispatches')
                ->where('cleaning_booking_session_id', $visit->id)
                ->lockForUpdate()->first();

            if ($existing !== null) {
                return $this->project($visit, $now);
            }
            if ($visit->isTerminal() || ! $this->hasFutureAppointment($visit, $now)) {
                throw new DomainException('V3_DISPATCH_REQUIRES_FUTURE_VISIT');
            }

            $accepted = $this->acceptedCount($visit);
            $covered = $accepted >= $visit->requiredWorkerCount();

            DB::table('cleaning_v3_visit_dispatches')->insert([
                'cleaning_booking_session_id' => $visit->id,
                'first_search_started_at' => $now,
                'deadline_at' => $now->addMinutes(self::THRESHOLD_MINUTES),
                'first_fully_covered_at' => $covered ? $now : null,
                'resolved_at' => $covered ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->project($visit, $now);
        }, 3);
    }

    /**
     * Update only the coverage snapshot; do not fabricate a search start on
     * the first worker acceptance. V3 booking creation must explicitly call
     * openSearch as the visit becomes discoverable.
     */
    public function reconcileCoverage(CleaningBookingSession $session, ?CarbonInterface $clock = null): void
    {
        if ((string) ($session->booking->contract_version ?? '') !== 'v3') {
            return;
        }

        DB::transaction(function () use ($session, $clock): void {
            $visit = $this->lockVisit($session);
            $record = DB::table('cleaning_v3_visit_dispatches')
                ->where('cleaning_booking_session_id', $visit->id)
                ->lockForUpdate()->first();

            if ($record === null) {
                return;
            }

            $now = $this->clock($clock);
            $covered = $this->acceptedCount($visit) >= $visit->requiredWorkerCount();
            if ($covered && $record->resolved_at === null) {
                DB::table('cleaning_v3_visit_dispatches')->where('id', $record->id)
                    ->update([
                        'first_fully_covered_at' => $record->first_fully_covered_at ?? $now,
                        'resolved_at' => $now,
                        'updated_at' => $now,
                    ]);
            } elseif (! $covered && $record->resolved_at !== null) {
                // A withdrawal reopens the shortage, but the first-ever
                // coverage timestamp and initial six-hour deadline remain.
                DB::table('cleaning_v3_visit_dispatches')->where('id', $record->id)
                    ->update(['resolved_at' => null, 'updated_at' => $now]);
            }
        }, 3);
    }

    /**
     * Persist exactly one escalation and two delivery intentions per visit.
     * Notifications are deliberately not marked sent by this transaction:
     * the separate reliable delivery adapter must consume the outbox.
     */
    public function escalateDue(?CarbonInterface $clock = null, int $batchLimit = 100): int
    {
        $now = $this->clock($clock);
        $ids = DB::table('cleaning_v3_visit_dispatches')
            ->whereNull('escalated_at')
            ->whereNull('resolved_at')
            ->where('deadline_at', '<=', $now)
            ->orderBy('deadline_at')->orderBy('id')
            ->limit(max(1, min($batchLimit, 1000)))
            ->pluck('cleaning_booking_session_id');

        $escalated = 0;

        foreach ($ids as $visitId) {
            $wasEscalated = DB::transaction(function () use ($visitId, $now): bool {
                $visit = CleaningBookingSession::query()->whereKey((int) $visitId)
                    ->lockForUpdate()->first();
                if (! $visit instanceof CleaningBookingSession
                    || (string) ($visit->booking->contract_version ?? '') !== 'v3') {
                    return false;
                }

                $record = DB::table('cleaning_v3_visit_dispatches')
                    ->where('cleaning_booking_session_id', $visit->id)
                    ->lockForUpdate()->first();
                if ($record === null || $record->escalated_at !== null
                    || $record->resolved_at !== null
                    || CarbonImmutable::parse($record->deadline_at, 'UTC') > $now) {
                    return false;
                }
                if ($visit->isTerminal()) {
                    return false;
                }

                if ($this->acceptedCount($visit) >= $visit->requiredWorkerCount()) {
                    DB::table('cleaning_v3_visit_dispatches')->where('id', $record->id)
                        ->update([
                            'first_fully_covered_at' => $record->first_fully_covered_at ?? $now,
                            'resolved_at' => $now,
                            'updated_at' => $now,
                        ]);

                    return false;
                }

                DB::table('cleaning_v3_visit_dispatches')->where('id', $record->id)
                    ->update(['escalated_at' => $now, 'updated_at' => $now]);

                foreach (['customer', 'admin'] as $recipient) {
                    DB::table('cleaning_v3_dispatch_notice_intents')->insertOrIgnore([
                        'cleaning_v3_visit_dispatch_id' => $record->id,
                        'recipient_kind' => $recipient,
                        'event_key' => 'six_hour_incomplete',
                        'queued_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return true;
            }, 3);

            $escalated += $wasEscalated ? 1 : 0;
        }

        return $escalated;
    }

    /** @return array<string,mixed> */
    public function project(CleaningBookingSession $session, ?CarbonInterface $clock = null): array
    {
        if ((string) ($session->booking->contract_version ?? '') !== 'v3') {
            throw new DomainException('V3_DISPATCH_CONTRACT_REQUIRED');
        }

        $now = $this->clock($clock);
        $record = DB::table('cleaning_v3_visit_dispatches')
            ->where('cleaning_booking_session_id', $session->id)->first();

        $required = $session->requiredWorkerCount();
        $accepted = $this->acceptedCount($session);
        $remaining = max(0, $required - $accepted);
        $appointmentPast = ! $this->hasFutureAppointment($session, $now);

        $status = match (true) {
            $session->isTerminal() => 'closed',
            $record === null => 'not_started',
            $remaining === 0 => 'covered',
            $appointmentPast => 'reschedule_required',
            $record->escalated_at !== null => 'escalated',
            default => 'searching',
        };

        return [
            'visitId' => (int) $session->id,
            'requiredWorkers' => $required,
            'acceptedWorkers' => $accepted,
            'remainingWorkers' => $remaining,
            'firstSearchStartedAt' => $record?->first_search_started_at,
            'deadlineAt' => $record?->deadline_at,
            'escalatedAt' => $record?->escalated_at,
            'firstFullyCoveredAt' => $record?->first_fully_covered_at,
            'resolvedAt' => $record?->resolved_at,
            'dispatchStatus' => $status,
            'allowedActions' => [], // No unimplemented command is advertised.
            'serverNow' => $now->toIso8601String(),
        ];
    }

    private function acceptedCount(CleaningBookingSession $session): int
    {
        return $session->workerAssignments()
            ->whereNull('released_at')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->count();
    }

    private function hasFutureAppointment(CleaningBookingSession $session, CarbonImmutable $now): bool
    {
        $start = $session->startsAt();

        return $start !== null && $start->utc() > $now;
    }

    private function lockVisit(CleaningBookingSession $session): CleaningBookingSession
    {
        $visit = CleaningBookingSession::query()->whereKey($session->id)
            ->lockForUpdate()->firstOrFail();

        if ((string) ($visit->booking->contract_version ?? '') !== 'v3') {
            throw new DomainException('V3_DISPATCH_CONTRACT_REQUIRED');
        }

        return $visit;
    }

    private function clock(?CarbonInterface $clock): CarbonImmutable
    {
        return ($clock instanceof CarbonInterface
            ? CarbonImmutable::instance($clock)
            : CarbonImmutable::now('UTC'))->utc();
    }
}

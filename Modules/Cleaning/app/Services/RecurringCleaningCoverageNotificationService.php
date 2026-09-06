<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Throwable;

final class RecurringCleaningCoverageNotificationService
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    public function notifyCoverageChanged(
        CleaningBookingSession $session,
        ?CleaningBookingSessionCoverageStatus $previousCoverage,
        CleaningBookingSessionCoverageStatus $currentCoverage,
    ): void {
        if ($session->session_type !== CleaningBookingSession::TYPE_RECURRING_CLEANING) {
            return;
        }

        if ($previousCoverage === $currentCoverage) {
            return;
        }

        $booking = $session->booking()->with('customer')->first();
        if (! $booking instanceof CleaningBooking || $booking->customer === null) {
            return;
        }

        /** @var Collection<int, CleaningBookingSession> $sessions */
        $sessions = CleaningBookingSession::query()
            ->with('workerAssignments')
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->whereIn('status', CleaningBookingSessionStatus::activeValues())
            ->whereDate('scheduled_date', '>=', now(config('app.timezone'))->toDateString())
            ->orderBy('sequence')
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $totalSessions = $sessions->count();
        $fullyCoveredSessions = $sessions->filter(
            fn (CleaningBookingSession $candidate): bool => $candidate->remainingWorkerCount() === 0,
        )->count();
        $partiallyCoveredSessions = $sessions->filter(function (CleaningBookingSession $candidate): bool {
            $accepted = $candidate->acceptedWorkerCount();

            return $accepted > 0 && $candidate->remainingWorkerCount() > 0;
        })->count();
        $searchingSessions = max(0, $totalSessions - $fullyCoveredSessions - $partiallyCoveredSessions);
        $requiredSeats = $sessions->sum(
            fn (CleaningBookingSession $candidate): int => $candidate->requiredWorkerCount(),
        );
        $acceptedSeats = $sessions->sum(
            fn (CleaningBookingSession $candidate): int => min(
                $candidate->requiredWorkerCount(),
                $candidate->acceptedWorkerCount(),
            ),
        );
        $remainingSeats = max(0, $requiredSeats - $acceptedSeats);
        $canonicalType = $remainingSeats === 0
            ? 'cleaning.booking.recurring_coverage_complete'
            : 'cleaning.booking.recurring_coverage_progress';
        $summaryState = match (true) {
            $remainingSeats === 0 => CleaningBookingSessionCoverageStatus::FullyCovered->value,
            $acceptedSeats > 0 => CleaningBookingSessionCoverageStatus::PartiallyCovered->value,
            default => CleaningBookingSessionCoverageStatus::Searching->value,
        };
        $fingerprint = hash('sha256', implode('|', [
            (string) $booking->id,
            $canonicalType,
            (string) $totalSessions,
            (string) $fullyCoveredSessions,
            (string) $partiallyCoveredSessions,
            (string) $searchingSessions,
            (string) $requiredSeats,
            (string) $acceptedSeats,
            (string) $remainingSeats,
        ]));
        $dedupeKey = "cleaning:recurring-coverage:{$booking->id}:{$fingerprint}";
        $now = now(config('app.timezone'));

        $dispatch = CleaningNotificationDispatch::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'cleaning_booking_id' => $booking->id,
                'worker_assignment_id' => null,
                'recipient_user_id' => $booking->customer->getKey(),
                'canonical_type' => $canonicalType,
                'scheduled_at_snapshot' => $session->startsAt() ?? $now,
                'due_at' => $now,
                'status' => 'claimed',
                'attempts' => 1,
            ],
        );

        if (! $dispatch->wasRecentlyCreated) {
            return;
        }

        $status = $booking->status instanceof \BackedEnum
            ? (string) $booking->status->value
            : (string) $booking->status;

        try {
            $this->lifecycleNotifications->notifyCustomer(
                booking: $booking,
                canonicalType: $canonicalType,
                action: 'recurring_coverage_updated',
                actorRole: 'system',
                fromStatus: $status,
                occurredAt: $now->toIso8601String(),
                extraData: [
                    'sessionId' => (int) $session->id,
                    'previousCoverage' => $previousCoverage?->value,
                    'currentCoverage' => $currentCoverage->value,
                    'recurringCoverageState' => $summaryState,
                    'coveredSessions' => $fullyCoveredSessions,
                    'partiallyCoveredSessions' => $partiallyCoveredSessions,
                    'searchingSessions' => $searchingSessions,
                    'totalSessions' => $totalSessions,
                    'acceptedSeats' => $acceptedSeats,
                    'requiredSeats' => $requiredSeats,
                    'remainingSeats' => $remainingSeats,
                    'coverageFingerprint' => $fingerprint,
                ],
                templateContext: [
                    'covered_sessions' => $fullyCoveredSessions,
                    'total_sessions' => $totalSessions,
                    'remaining_seats' => $remainingSeats,
                ],
            );

            $dispatch->forceFill([
                'status' => 'sent',
                'sent_at' => $now,
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $dispatch->forceFill([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
            report($exception);
        }
    }
}

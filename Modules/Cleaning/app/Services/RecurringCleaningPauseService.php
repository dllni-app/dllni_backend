<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class RecurringCleaningPauseService
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $notifications,
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
    ) {}

    /** @return array{booking:CleaningBooking,pausedSessionIds:array<int,int>,releasedWorkerIds:array<int,int>} */
    public function pause(CleaningBooking $booking, int $customerId, string $reason): array
    {
        $this->assertCustomer($booking, $customerId);
        $normalizedReason = $this->requiredReason($reason);
        $pausedSessionIds = [];
        $releasedWorkerIds = [];

        DB::transaction(function () use (
            $booking,
            $normalizedReason,
            &$pausedSessionIds,
            &$releasedWorkerIds,
        ): void {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertRecurring($lockedBooking);
            if ($lockedBooking->recurring_paused_at !== null) {
                throw new InvalidArgumentException('Recurring cleaning series is already paused.');
            }

            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
                ->whereIn('status', [
                    CleaningBookingSessionStatus::Scheduled->value,
                    CleaningBookingSessionStatus::WorkerAssigned->value,
                ])
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $startsAt = $session->startsAt();
                if (
                    $startsAt === null
                    || ! $startsAt->isFuture()
                    || $session->started_travel_at !== null
                    || $session->work_started_at !== null
                ) {
                    continue;
                }

                $assignments = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->lockForUpdate()
                    ->get();

                if ($assignments->contains(
                    static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->started_travel_at !== null,
                )) {
                    continue;
                }

                $pausedAt = now();
                foreach ($assignments as $assignment) {
                    $workerId = (int) $assignment->worker_id;
                    if ($workerId > 0) {
                        $releasedWorkerIds[] = $workerId;
                    }
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => $pausedAt,
                        'released_reason' => 'Customer paused recurring cleaning series: '.$normalizedReason,
                    ])->save();
                }

                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::Paused,
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
                $pausedSessionIds[] = (int) $session->id;
            }

            if ($pausedSessionIds === []) {
                throw new InvalidArgumentException('No future recurring visits are eligible to pause.');
            }

            $lockedBooking->forceFill([
                'recurring_paused_at' => now(),
                'recurring_pause_reason' => $normalizedReason,
            ])->save();
        }, 3);

        $this->parentState->refresh($booking);
        $freshBooking = $booking->fresh(['customer']) ?? $booking;
        $releasedWorkerIds = array_values(array_unique($releasedWorkerIds));

        foreach ($releasedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $freshBooking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: 'customer_paused_recurring_series',
                actorRole: 'customer',
                occurredAt: $freshBooking->recurring_paused_at?->toIso8601String(),
                extraData: [
                    'pausedSessionIds' => $pausedSessionIds,
                    'pauseReason' => $normalizedReason,
                ],
            );
        }

        return [
            'booking' => $freshBooking,
            'pausedSessionIds' => $pausedSessionIds,
            'releasedWorkerIds' => $releasedWorkerIds,
        ];
    }

    /** @return array{booking:CleaningBooking,resumedSessionIds:array<int,int>,expiredSessionIds:array<int,int>} */
    public function resume(CleaningBooking $booking, int $customerId): array
    {
        $this->assertCustomer($booking, $customerId);
        $resumedSessionIds = [];
        $expiredSessionIds = [];

        DB::transaction(function () use (
            $booking,
            &$resumedSessionIds,
            &$expiredSessionIds,
        ): void {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertRecurring($lockedBooking);
            if ($lockedBooking->recurring_paused_at === null) {
                throw new InvalidArgumentException('Recurring cleaning series is not paused.');
            }

            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
                ->where('status', CleaningBookingSessionStatus::Paused->value)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($sessions->isEmpty()) {
                throw new InvalidArgumentException('Recurring cleaning series has no paused visits to resume.');
            }

            foreach ($sessions as $session) {
                $startsAt = $session->startsAt();
                if ($startsAt === null || ! $startsAt->isFuture()) {
                    $session->forceFill([
                        'status' => CleaningBookingSessionStatus::Skipped,
                        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                        'skipped_at' => now(),
                        'skip_source' => 'recurring_pause_expired',
                        'skip_reason' => 'Visit time passed while recurring cleaning series was paused.',
                        'cancellation_fee' => 0,
                        'version' => max(1, (int) $session->version) + 1,
                    ])->save();
                    $expiredSessionIds[] = (int) $session->id;

                    continue;
                }

                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::Scheduled,
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
                $resumedSessionIds[] = (int) $session->id;
            }

            $lockedBooking->forceFill([
                'recurring_paused_at' => null,
                'recurring_pause_reason' => null,
            ])->save();

            if ($expiredSessionIds !== []) {
                $this->financialAggregation->sync($lockedBooking);
            }
        }, 3);

        $this->parentState->refresh($booking);

        return [
            'booking' => $booking->fresh(['customer']) ?? $booking,
            'resumedSessionIds' => $resumedSessionIds,
            'expiredSessionIds' => $expiredSessionIds,
        ];
    }

    private function assertCustomer(CleaningBooking $booking, int $customerId): void
    {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }
    }

    private function assertRecurring(CleaningBooking $booking): void
    {
        $hasRecurringSessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->exists();

        if (! $hasRecurringSessions) {
            throw new InvalidArgumentException('Only recurring cleaning bookings can be paused or resumed.');
        }
    }

    private function requiredReason(string $reason): string
    {
        $normalized = mb_trim($reason);
        if ($normalized === '') {
            throw new InvalidArgumentException('Pause reason is required.');
        }

        return mb_substr($normalized, 0, 1000);
    }
}

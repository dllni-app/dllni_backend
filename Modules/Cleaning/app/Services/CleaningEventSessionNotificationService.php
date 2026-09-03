<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Throwable;

final class CleaningEventSessionNotificationService
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    public function dispatchDueReminders(?CarbonInterface $clock = null): int
    {
        $timezone = (string) config('cleaning_action_notifications.timezone', config('app.timezone'));
        $now = $clock instanceof CarbonInterface
            ? CarbonImmutable::instance($clock)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
        $reminderMinutes = max(1, (int) config('cleaning_action_notifications.event_session_reminder_minutes', 60));
        $windowEnd = $now->addMinutes($reminderMinutes + 5);
        $sent = 0;

        CleaningBookingSession::query()
            ->with(['booking.customer', 'workerAssignments.worker.user'])
            ->whereHas('booking', fn (Builder $query): Builder => $query->where('property_type', 'event_assistance'))
            ->whereIn('status', [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
            ])
            ->whereDate('scheduled_date', '>=', $now->toDateString())
            ->whereDate('scheduled_date', '<=', $windowEnd->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->chunkById(100, function ($sessions) use ($now, $reminderMinutes, &$sent): void {
                foreach ($sessions as $session) {
                    if (! $session instanceof CleaningBookingSession) {
                        continue;
                    }

                    $startsAt = $session->startsAt();
                    if ($startsAt === null) {
                        continue;
                    }

                    $minutesUntilStart = $now->diffInMinutes($startsAt, false);
                    if ($minutesUntilStart < 0 || $minutesUntilStart > $reminderMinutes) {
                        continue;
                    }

                    $booking = $session->booking;
                    if (! $booking instanceof CleaningBooking) {
                        continue;
                    }

                    if ($booking->customer instanceof User && $this->claim(
                        $booking,
                        $session,
                        (int) $booking->customer->id,
                        'customer',
                        $startsAt,
                        $now,
                    )) {
                        $this->safeNotifyCustomer($booking, $session, $startsAt, $minutesUntilStart, $now);
                        $sent++;
                    }

                    foreach ($session->workerAssignments as $assignment) {
                        if (! $assignment->isAccepted() || ! $assignment->worker?->user instanceof User) {
                            continue;
                        }

                        $workerUser = $assignment->worker->user;
                        if (! $this->claim(
                            $booking,
                            $session,
                            (int) $workerUser->id,
                            'worker',
                            $startsAt,
                            $now,
                        )) {
                            continue;
                        }

                        $this->safeNotifyWorker(
                            $booking,
                            $session,
                            (int) $assignment->worker_id,
                            $startsAt,
                            $minutesUntilStart,
                            $now,
                        );
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    public function notifyCompleted(CleaningBooking $booking, CleaningBookingSession $session): void
    {
        if (! $booking->isEventAssistanceBooking()) {
            return;
        }

        $booking->loadMissing('customer');
        $next = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereNotIn('status', CleaningBookingSessionStatus::terminalValues())
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->first();
        $isFinal = $next === null;
        $canonicalType = 'cleaning.booking.completion_approved';
        $action = $isFinal ? 'event_completed' : 'event_day_completed';
        $context = [
            'sessionId' => (int) $session->id,
            'daySequence' => (int) $session->sequence,
            'isFinalEventDay' => $isFinal,
            'nextSessionId' => $next?->id,
            'nextScheduledDate' => $next?->scheduled_date?->toDateString(),
            'nextScheduledTime' => $next?->scheduled_time,
        ];

        try {
            $this->notifications->notifyCustomer(
                booking: $booking,
                canonicalType: $canonicalType,
                action: $action,
                actorRole: 'customer',
                occurredAt: now()->toIso8601String(),
                extraData: $context,
                templateContext: [
                    'session_number' => (int) $session->sequence,
                    'next_appointment' => $next !== null
                        ? $next->scheduled_date?->format('Y-m-d').' '.$next->scheduled_time
                        : null,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function claim(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $recipientId,
        string $role,
        CarbonImmutable $startsAt,
        CarbonImmutable $now,
    ): bool {
        $key = hash('sha256', implode('|', [
            'event-session-reminder',
            $booking->id,
            $session->id,
            $recipientId,
            $role,
            $startsAt->toIso8601String(),
        ]));

        $dispatch = CleaningNotificationDispatch::query()->firstOrCreate(
            ['dedupe_key' => $key],
            [
                'cleaning_booking_id' => $booking->id,
                'recipient_user_id' => $recipientId,
                'canonical_type' => 'cleaning.booking.customer_upcoming_start_reminder',
                'scheduled_at_snapshot' => $startsAt,
                'due_at' => $startsAt->subMinutes(max(1, (int) config('cleaning_action_notifications.event_session_reminder_minutes', 60))),
                'status' => 'sent',
                'attempts' => 1,
                'sent_at' => $now,
            ],
        );

        return $dispatch->wasRecentlyCreated;
    }

    private function safeNotifyCustomer(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        CarbonImmutable $startsAt,
        int $minutesUntilStart,
        CarbonImmutable $now,
    ): void {
        try {
            $this->notifications->notifyCustomer(
                booking: $booking,
                canonicalType: 'cleaning.booking.customer_upcoming_start_reminder',
                action: 'prepare_for_event_day',
                actorRole: 'system',
                occurredAt: $now->toIso8601String(),
                extraData: [
                    'sessionId' => (int) $session->id,
                    'daySequence' => (int) $session->sequence,
                    'scheduledAt' => $startsAt->toIso8601String(),
                    'minutesUntilStart' => $minutesUntilStart,
                ],
                templateContext: [
                    'scheduled_time' => $startsAt->format('H:i'),
                    'minutes_until_start' => $minutesUntilStart,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function safeNotifyWorker(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $workerId,
        CarbonImmutable $startsAt,
        int $minutesUntilStart,
        CarbonImmutable $now,
    ): void {
        try {
            $this->notifications->notifyWorkerById(
                booking: $booking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.worker_travel_start_reminder',
                action: 'prepare_for_event_day',
                actorRole: 'system',
                occurredAt: $now->toIso8601String(),
                extraData: [
                    'sessionId' => (int) $session->id,
                    'daySequence' => (int) $session->sequence,
                    'scheduledAt' => $startsAt->toIso8601String(),
                    'minutesUntilStart' => $minutesUntilStart,
                ],
                templateContext: [
                    'scheduled_time' => $startsAt->format('H:i'),
                    'minutes_until_start' => $minutesUntilStart,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

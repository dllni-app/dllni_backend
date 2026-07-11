<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Throwable;

final class CleaningBookingActionNotificationService
{
    public function __construct(
        private readonly CleaningBookingActionNotificationRuleEngine $ruleEngine,
        private readonly CleaningLegacyBookingActionNotificationRuleEngine $legacyRuleEngine,
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    public function dispatchDue(?CarbonInterface $clock = null): int
    {
        if (! config('cleaning_action_notifications.enabled', true)) {
            return 0;
        }

        $timezone = (string) config('cleaning_action_notifications.timezone', config('app.timezone'));
        $now = $clock instanceof CarbonInterface
            ? CarbonImmutable::instance($clock)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
        $lookahead = max(1, (int) config('cleaning_action_notifications.lookahead_minutes', 65));
        $lookback = max(1, (int) config('cleaning_action_notifications.lookback_minutes', 180));
        $chunkSize = max(1, (int) config('cleaning_action_notifications.chunk_size', 100));
        $sent = 0;

        CleaningBooking::query()
            ->with(['customer', 'worker.user', 'workerAssignments.worker.user'])
            ->whereIn('status', [
                CleaningBookingStatus::Pending->value,
                CleaningBookingStatus::WorkerAssigned->value,
                CleaningBookingStatus::AwaitingStartVerification->value,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
            ])
            ->whereDate('scheduled_date', '>=', $now->subMinutes($lookback)->toDateString())
            ->whereDate('scheduled_date', '<=', $now->addMinutes($lookahead)->toDateString())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($bookings) use ($now, &$sent): void {
                foreach ($bookings as $booking) {
                    if (! $booking instanceof CleaningBooking) {
                        continue;
                    }

                    $rules = array_merge(
                        $this->ruleEngine->dueNotifications($booking, $now),
                        $this->legacyRuleEngine->dueNotifications($booking, $now),
                    );

                    foreach ($rules as $rule) {
                        if ($this->dispatchRule($booking, $rule, $now)) {
                            $sent++;
                        }
                    }
                }
            });

        return $sent;
    }

    /** @param array<string, mixed> $rule */
    private function dispatchRule(CleaningBooking $booking, array $rule, CarbonImmutable $now): bool
    {
        $assignment = $rule['assignment'] ?? null;
        $assignment = $assignment instanceof CleaningBookingWorkerAssignment ? $assignment : null;
        $recipient = $rule['recipient'];
        $canonicalType = (string) $rule['canonicalType'];
        $scheduledAt = $rule['scheduledAt'];
        $dueAt = $rule['dueAt'];

        if (! $scheduledAt instanceof CarbonImmutable || ! $dueAt instanceof CarbonImmutable) {
            return false;
        }

        $dedupeKey = $this->dedupeKey(
            bookingId: (int) $booking->id,
            assignmentId: $assignment?->id,
            recipientId: (int) $recipient->getKey(),
            canonicalType: $canonicalType,
            scheduledAt: $scheduledAt,
        );

        $dispatch = CleaningNotificationDispatch::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'cleaning_booking_id' => $booking->id,
                'worker_assignment_id' => $assignment?->id,
                'recipient_user_id' => $recipient->getKey(),
                'canonical_type' => $canonicalType,
                'scheduled_at_snapshot' => $scheduledAt,
                'due_at' => $dueAt,
                'status' => 'claimed',
                'attempts' => 1,
            ],
        );

        if (! $dispatch->wasRecentlyCreated) {
            $retryAfter = max(1, (int) config('cleaning_action_notifications.retry_failed_after_minutes', 5));
            $claimed = CleaningNotificationDispatch::query()
                ->whereKey($dispatch->id)
                ->where('status', 'failed')
                ->where('updated_at', '<=', $now->subMinutes($retryAfter))
                ->update([
                    'status' => 'claimed',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => null,
                    'updated_at' => $now,
                ]);

            if ($claimed !== 1) {
                return false;
            }

            $dispatch->refresh();
        }

        $targetRole = (string) ($rule['targetRole'] ?? 'customer');
        $deadlineAt = $rule['deadlineAt'] ?? null;
        $extraData = array_filter([
            'assignmentId' => $assignment?->id,
            'workerId' => $assignment?->worker_id ?? ($targetRole === 'worker' ? $booking->worker_id : null),
            'scheduledAt' => $scheduledAt->toIso8601String(),
            'deadlineAt' => $deadlineAt instanceof CarbonImmutable ? $deadlineAt->toIso8601String() : null,
            'requiredAction' => (string) $rule['requiredAction'],
            'reminderKind' => (string) $rule['reminderKind'],
            'minutesUntilStart' => (int) $rule['minutesUntilStart'],
            'severity' => (string) $rule['severity'],
            'dedupeKey' => $dedupeKey,
        ], static fn (mixed $value): bool => $value !== null);
        $templateContext = [
            'scheduled_time' => $scheduledAt->format('H:i'),
            'minutes_until_start' => max(0, (int) $rule['minutesUntilStart']),
            'required_workers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'accepted_workers' => $booking->acceptedWorkerCount(),
        ];
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

        try {
            if ($targetRole === 'worker') {
                if ($assignment instanceof CleaningBookingWorkerAssignment) {
                    $this->lifecycleNotifications->notifyWorkerAssignment(
                        booking: $booking,
                        assignment: $assignment,
                        canonicalType: $canonicalType,
                        action: (string) $rule['action'],
                        actorRole: 'system',
                        fromStatus: $status,
                        occurredAt: $now->toIso8601String(),
                        extraData: $extraData,
                        templateContext: $templateContext,
                    );
                } else {
                    $this->lifecycleNotifications->notifyWorker(
                        booking: $booking,
                        canonicalType: $canonicalType,
                        action: (string) $rule['action'],
                        actorRole: 'system',
                        fromStatus: $status,
                        occurredAt: $now->toIso8601String(),
                        extraData: $extraData,
                        templateContext: $templateContext,
                    );
                }
            } else {
                $this->lifecycleNotifications->notifyCustomer(
                    booking: $booking,
                    canonicalType: $canonicalType,
                    action: (string) $rule['action'],
                    actorRole: 'system',
                    fromStatus: $status,
                    occurredAt: $now->toIso8601String(),
                    extraData: $extraData,
                    templateContext: $templateContext,
                );
            }

            $dispatch->forceFill([
                'status' => 'sent',
                'sent_at' => $now,
                'last_error' => null,
            ])->save();

            return true;
        } catch (Throwable $exception) {
            $dispatch->forceFill([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
            report($exception);

            return false;
        }
    }

    private function dedupeKey(
        int $bookingId,
        ?int $assignmentId,
        int $recipientId,
        string $canonicalType,
        CarbonImmutable $scheduledAt,
    ): string {
        $scope = $assignmentId !== null ? "assignment:{$assignmentId}" : "recipient:{$recipientId}";
        $fingerprint = hash('sha256', $canonicalType.'|'.$scheduledAt->toIso8601String());

        return "cleaning:{$bookingId}:{$scope}:{$fingerprint}";
    }
}

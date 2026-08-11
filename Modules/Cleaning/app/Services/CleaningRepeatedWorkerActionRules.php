<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningRepeatedWorkerActionRules
{
    public function __construct(
        private readonly CleaningActionReminderRepeatSchedule $repeatSchedule,
        private readonly CleaningRepeatedNotificationRuleFactory $rules,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function dueNotifications(
        CleaningBooking $booking,
        CarbonImmutable $now,
        CarbonImmutable $scheduledAt,
        CleaningBookingStatus $status,
        int $minutesUntilStart,
    ): array {
        $workers = $this->workerContexts($booking);
        $rules = [];

        if (in_array($status, [
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        ], true)) {
            $rules = array_merge($rules, $this->travelAndArrivalRules($workers, $now, $scheduledAt, $minutesUntilStart));
        }

        if ($status === CleaningBookingStatus::AwaitingStartVerification) {
            $rules = array_merge($rules, $this->securityCodeIssueRules($booking, $workers, $now, $scheduledAt, $minutesUntilStart));
        }

        if (in_array($status, [
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        ], true)) {
            $rules = array_merge($rules, $this->startConfirmationRules($workers, $now, $scheduledAt, $minutesUntilStart));
        }

        if ($status === CleaningBookingStatus::TimeExtensionRequested) {
            $rule = $this->extensionResponseRule($booking, $workers, $now, $scheduledAt, $minutesUntilStart);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /** @param array<int, array<string, mixed>> $workers @return array<int, array<string, mixed>> */
    private function travelAndArrivalRules(array $workers, CarbonImmutable $now, CarbonImmutable $scheduledAt, int $minutes): array
    {
        $rules = [];

        foreach ($workers as $worker) {
            if ($worker['startedTravelAt'] === null) {
                $firstDueAt = $scheduledAt->subMinutes(5);
                $repeat = $this->repeat('worker_start_travel_warning', $now, $firstDueAt, ':follow-up');
                if ($repeat !== null) {
                    $rules[] = $this->rules->worker(
                        $worker,
                        'cleaning.booking.worker_start_travel_warning',
                        'start_travel',
                        'start_travel',
                        'warning',
                        'high',
                        $scheduledAt,
                        $scheduledAt,
                        $minutes,
                        $repeat,
                    );
                }
                continue;
            }

            if ($worker['arrivedAt'] === null) {
                $firstDueAt = $scheduledAt->addMinutes(10);
                $repeat = $this->repeat('worker_arrival_critical_warning', $now, $firstDueAt, ':follow-up');
                if ($repeat !== null) {
                    $rules[] = $this->rules->worker(
                        $worker,
                        'cleaning.booking.worker_arrival_critical_warning',
                        'mark_arrival',
                        'mark_arrival',
                        'critical_warning',
                        'high',
                        $scheduledAt,
                        $scheduledAt,
                        $minutes,
                        $repeat,
                    );
                }
            }
        }

        return $rules;
    }

    /** @param array<int, array<string, mixed>> $workers @return array<int, array<string, mixed>> */
    private function securityCodeIssueRules(
        CleaningBooking $booking,
        array $workers,
        CarbonImmutable $now,
        CarbonImmutable $scheduledAt,
        int $minutes,
    ): array {
        $rules = [];
        $activeCodeWorkerIds = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->pluck('worker_id')
            ->map(fn ($id): ?int => $id !== null ? (int) $id : null)
            ->all();

        foreach ($workers as $worker) {
            if (
                ! $worker['arrivedAt'] instanceof CarbonInterface
                || $worker['verifiedAt'] !== null
                || $worker['workStartedAt'] !== null
                || in_array($worker['workerId'], $activeCodeWorkerIds, true)
                || ($worker['assignment'] === null && in_array(null, $activeCodeWorkerIds, true))
            ) {
                continue;
            }

            $firstDueAt = $this->securityCodeIssueAnchor(
                $booking,
                $worker['workerId'],
                CarbonImmutable::instance($worker['arrivedAt']),
                $now,
                $worker['assignment'] === null,
            );
            $repeat = $this->repeat(
                'worker_security_code_issue_reminder',
                $now,
                $firstDueAt,
                ':anchor:'.$firstDueAt->getTimestamp(),
            );

            if ($repeat !== null) {
                $rules[] = $this->rules->worker(
                    $worker,
                    'cleaning.booking.worker_security_code_issue_reminder',
                    'issue_security_code',
                    'issue_security_code',
                    'reminder',
                    'high',
                    null,
                    $scheduledAt,
                    $minutes,
                    $repeat,
                );
            }
        }

        return $rules;
    }

    /** @param array<int, array<string, mixed>> $workers @return array<int, array<string, mixed>> */
    private function startConfirmationRules(array $workers, CarbonImmutable $now, CarbonImmutable $scheduledAt, int $minutes): array
    {
        $rules = [];

        foreach ($workers as $worker) {
            if (! $worker['verifiedAt'] instanceof CarbonInterface || $worker['workStartedAt'] !== null) {
                continue;
            }

            $verifiedAt = CarbonImmutable::instance($worker['verifiedAt']);
            if (
                $worker['assignment'] instanceof CleaningBookingWorkerAssignment
                && $now->greaterThanOrEqualTo($verifiedAt->addMinutes(2))
                && $now->lessThan($verifiedAt->addMinutes(5))
            ) {
                $rules[] = $this->rules->worker(
                    $worker,
                    'cleaning.booking.worker_start_confirmation_reminder',
                    'start_work',
                    'start_work',
                    'reminder',
                    'normal',
                    $verifiedAt->addMinutes(5),
                    $scheduledAt,
                    $minutes,
                    [
                        'dueAt' => $verifiedAt->addMinutes(2),
                        'occurrenceKey' => 'assignment-start:'.$verifiedAt->getTimestamp(),
                        'repeatNumber' => 1,
                        'maxRepeats' => 1,
                    ],
                );
            }

            $firstDueAt = $verifiedAt->addMinutes(
                $worker['assignment'] instanceof CleaningBookingWorkerAssignment ? 5 : 10
            );
            $repeat = $this->repeat(
                'worker_start_confirmation_warning',
                $now,
                $firstDueAt,
                ':verified:'.$verifiedAt->getTimestamp(),
            );
            if ($repeat !== null) {
                $rules[] = $this->rules->worker(
                    $worker,
                    'cleaning.booking.worker_start_confirmation_warning',
                    'start_work',
                    'start_work',
                    'warning',
                    'high',
                    $verifiedAt->addMinutes(5),
                    $scheduledAt,
                    $minutes,
                    $repeat,
                );
            }
        }

        return $rules;
    }

    /** @param array<int, array<string, mixed>> $workers @return array<string, mixed>|null */
    private function extensionResponseRule(
        CleaningBooking $booking,
        array $workers,
        CarbonImmutable $now,
        CarbonImmutable $scheduledAt,
        int $minutes,
    ): ?array {
        $warning = $booking->timeWarnings
            ->filter(fn (CleaningTimeWarning $item): bool => $item->worker_responded_at === null)
            ->sortByDesc('id')
            ->first();
        $anchor = $warning instanceof CleaningTimeWarning
            ? ($warning->customer_responded_at ?? $warning->sent_at ?? $warning->created_at)
            : null;

        if (! $warning instanceof CleaningTimeWarning || ! $anchor instanceof CarbonInterface) {
            return null;
        }

        $worker = null;
        foreach ($workers as $candidate) {
            if ($warning->worker_id === null || $candidate['workerId'] === (int) $warning->worker_id) {
                $worker = $candidate;
                break;
            }
        }
        if (! is_array($worker)) {
            return null;
        }

        $firstDueAt = CarbonImmutable::instance($anchor)->addMinutes(5);
        $repeat = $this->repeat(
            'worker_extension_response_reminder',
            $now,
            $firstDueAt,
            ':warning:'.$warning->id,
        );

        return $repeat === null ? null : $this->rules->worker(
            $worker,
            'cleaning.booking.worker_extension_response_reminder',
            'review_extension_request',
            'accept_or_reject_extension',
            'reminder',
            'high',
            null,
            $scheduledAt,
            $minutes,
            $repeat,
            ['warningId' => $warning->id],
        );
    }

    /** @return array<string, mixed>|null */
    private function repeat(string $policy, CarbonImmutable $now, CarbonImmutable $firstDueAt, string $suffix): ?array
    {
        $repeat = $this->repeatSchedule->occurrence(
            $policy,
            $now,
            $firstDueAt,
            $this->repeatSchedule->configuredUntil($policy, $firstDueAt),
        );
        if ($repeat !== null) {
            $repeat['occurrenceKey'] .= $suffix;
        }

        return $repeat;
    }

    /** @return array<int, array<string, mixed>> */
    private function workerContexts(CleaningBooking $booking): array
    {
        $assignments = $booking->workerAssignments
            ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => in_array(
                $this->assignmentStatus($assignment),
                CleaningBookingWorkerAssignmentStatus::activeValues(),
                true,
            ));

        if ($assignments->isNotEmpty()) {
            return $assignments
                ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => $assignment->worker?->user instanceof User)
                ->map(fn (CleaningBookingWorkerAssignment $assignment): array => [
                    'recipient' => $assignment->worker->user,
                    'workerId' => (int) $assignment->worker_id,
                    'assignment' => $assignment,
                    'startedTravelAt' => $assignment->started_travel_at,
                    'arrivedAt' => $assignment->arrived_at,
                    'verifiedAt' => $assignment->start_approved_at,
                    'workStartedAt' => $assignment->work_started_at,
                ])
                ->values()
                ->all();
        }

        if (! $booking->worker?->user instanceof User || $booking->worker_id === null) {
            return [];
        }

        return [[
            'recipient' => $booking->worker->user,
            'workerId' => (int) $booking->worker_id,
            'assignment' => null,
            'startedTravelAt' => $booking->started_travel_at,
            'arrivedAt' => $booking->arrived_at,
            'verifiedAt' => $booking->customer_confirmed_at,
            'workStartedAt' => $booking->work_started_at,
        ]];
    }

    private function securityCodeIssueAnchor(
        CleaningBooking $booking,
        int $workerId,
        CarbonImmutable $arrivedAt,
        CarbonImmutable $now,
        bool $includeNullWorker,
    ): CarbonImmutable {
        $timezone = (string) config('cleaning_action_notifications.timezone', config('app.timezone'));
        $query = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass());

        if ($includeNullWorker) {
            $query->where(fn ($scope) => $scope->where('worker_id', $workerId)->orWhereNull('worker_id'));
        } else {
            $query->where('worker_id', $workerId);
        }

        $arrivalAnchor = $arrivedAt->addMinutes(10);
        $latestExpiry = $query->orderByDesc('id')->value('expires_at');
        if ($latestExpiry === null) {
            return $arrivalAnchor;
        }

        $expiry = CarbonImmutable::parse((string) $latestExpiry, $timezone);
        if ($expiry->greaterThan($now)) {
            return $arrivalAnchor;
        }

        $expiredAnchor = $expiry->addMinutes(2);

        return $expiredAnchor->greaterThan($arrivalAnchor) ? $expiredAnchor : $arrivalAnchor;
    }

    private function assignmentStatus(CleaningBookingWorkerAssignment $assignment): string
    {
        return $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningRepeatedCustomerActionRules
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
        if (! $booking->customer instanceof User) {
            return [];
        }

        $rules = [];
        if ($status === CleaningBookingStatus::AwaitingStartVerification) {
            $rules = array_merge(
                $rules,
                $this->securityCodeRules($booking, $now, $scheduledAt, $minutesUntilStart),
            );
        }

        if (
            $status === CleaningBookingStatus::AwaitingCustomerCompletion
            && $booking->work_finished_at instanceof CarbonInterface
        ) {
            $finishedAt = CarbonImmutable::instance($booking->work_finished_at);
            $firstDueAt = $finishedAt->addMinutes(5);
            $repeat = $this->repeat(
                'customer_completion_action_reminder',
                $now,
                $firstDueAt,
                ':finished:'.$finishedAt->getTimestamp(),
            );

            if ($repeat !== null) {
                $rules[] = $this->rules->customer(
                    $booking->customer,
                    'cleaning.booking.customer_completion_action_reminder',
                    'review_completion_request',
                    'confirm_reject_or_extend_completion',
                    'reminder',
                    'high',
                    null,
                    $scheduledAt,
                    $minutesUntilStart,
                    $repeat,
                );
            }
        }

        return $rules;
    }

    /** @return array<int, array<string, mixed>> */
    private function securityCodeRules(
        CleaningBooking $booking,
        CarbonImmutable $now,
        CarbonImmutable $scheduledAt,
        int $minutes,
    ): array {
        $timezone = (string) config('cleaning_action_notifications.timezone', config('app.timezone'));
        $records = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->orderBy('id')
            ->get(['id', 'worker_id', 'created_at', 'expires_at']);
        $rules = [];

        foreach ($records as $record) {
            if ($record->created_at === null || $record->expires_at === null) {
                continue;
            }

            $createdAt = CarbonImmutable::parse((string) $record->created_at, $timezone);
            $expiresAt = CarbonImmutable::parse((string) $record->expires_at, $timezone);
            $repeat = $this->repeatSchedule->occurrence(
                'customer_verification_reminder',
                $now,
                $createdAt->addMinutes(2),
                $expiresAt->subMinutes(2),
            );
            if ($repeat === null) {
                continue;
            }

            $repeat['occurrenceKey'] .= ':code:'.$record->id;
            $rules[] = $this->rules->customer(
                $booking->customer,
                'cleaning.booking.customer_verification_reminder',
                'verify_security_code',
                'verify_security_code',
                'reminder',
                'normal',
                $expiresAt,
                $scheduledAt,
                $minutes,
                $repeat,
                [
                    'securityCodeId' => (int) $record->id,
                    'workerId' => $record->worker_id !== null ? (int) $record->worker_id : null,
                ],
            );
        }

        return $rules;
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
}

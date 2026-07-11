<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningLegacyBookingActionNotificationRuleEngine
{
    public function __construct(
        private readonly CleaningBookingScheduledAtResolver $scheduledAtResolver,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function dueNotifications(CleaningBooking $booking, CarbonImmutable $now): array
    {
        if ($booking->workerAssignments->isNotEmpty()) {
            return [];
        }

        $workerUser = $booking->worker?->user;
        if (! $workerUser instanceof User) {
            return [];
        }

        $scheduledAt = $this->scheduledAtResolver->resolve($booking);
        if (! $scheduledAt instanceof CarbonImmutable) {
            return [];
        }

        $customer = $booking->customer;
        $minutesUntilStart = (int) floor($now->diffInMinutes($scheduledAt, false));
        $rules = [];

        if ($this->within($now, $scheduledAt->subMinutes(60), $scheduledAt->subMinutes(30))) {
            if ($customer instanceof User) {
                $rules[] = $this->rule(
                    recipient: $customer,
                    targetRole: 'customer',
                    canonicalType: 'cleaning.booking.customer_upcoming_start_reminder',
                    action: 'prepare_for_booking',
                    requiredAction: 'prepare_for_booking',
                    reminderKind: 'reminder',
                    severity: 'normal',
                    dueAt: $scheduledAt->subMinutes(60),
                    deadlineAt: $scheduledAt,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }

            $rules[] = $this->rule(
                recipient: $workerUser,
                targetRole: 'worker',
                canonicalType: 'cleaning.booking.worker_upcoming_start_reminder',
                action: 'prepare_for_booking',
                requiredAction: 'prepare_for_booking',
                reminderKind: 'reminder',
                severity: 'normal',
                dueAt: $scheduledAt->subMinutes(60),
                deadlineAt: $scheduledAt,
                scheduledAt: $scheduledAt,
                minutesUntilStart: $minutesUntilStart,
            );
        }

        if ($booking->started_travel_at === null) {
            if ($this->within($now, $scheduledAt->subMinutes(30), $scheduledAt->subMinutes(10))) {
                $rules[] = $this->rule(
                    recipient: $workerUser,
                    targetRole: 'worker',
                    canonicalType: 'cleaning.booking.worker_start_travel_reminder',
                    action: 'start_travel',
                    requiredAction: 'start_travel',
                    reminderKind: 'reminder',
                    severity: 'normal',
                    dueAt: $scheduledAt->subMinutes(30),
                    deadlineAt: $scheduledAt,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }

            if ($this->within($now, $scheduledAt->subMinutes(10), $scheduledAt)) {
                $rules[] = $this->rule(
                    recipient: $workerUser,
                    targetRole: 'worker',
                    canonicalType: 'cleaning.booking.worker_start_travel_warning',
                    action: 'start_travel',
                    requiredAction: 'start_travel',
                    reminderKind: 'warning',
                    severity: 'high',
                    dueAt: $scheduledAt->subMinutes(10),
                    deadlineAt: $scheduledAt,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }
        }

        if ($booking->arrived_at === null) {
            if ($this->within($now, $scheduledAt, $scheduledAt->addMinutes(5))) {
                $rules[] = $this->rule(
                    recipient: $workerUser,
                    targetRole: 'worker',
                    canonicalType: 'cleaning.booking.worker_arrival_warning',
                    action: 'mark_arrival',
                    requiredAction: 'mark_arrival',
                    reminderKind: 'warning',
                    severity: 'high',
                    dueAt: $scheduledAt,
                    deadlineAt: $scheduledAt,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }

            if ($this->within($now, $scheduledAt->addMinutes(5), $scheduledAt->addMinutes(60))) {
                $rules[] = $this->rule(
                    recipient: $workerUser,
                    targetRole: 'worker',
                    canonicalType: 'cleaning.booking.worker_arrival_critical_warning',
                    action: 'mark_arrival',
                    requiredAction: 'mark_arrival',
                    reminderKind: 'critical_warning',
                    severity: 'high',
                    dueAt: $scheduledAt->addMinutes(5),
                    deadlineAt: $scheduledAt,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }
        }

        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);

        if (
            $status === CleaningBookingStatus::AwaitingWorkerStartConfirmation
            && $booking->customer_confirmed_at instanceof CarbonInterface
            && $booking->work_started_at === null
        ) {
            $confirmedAt = CarbonImmutable::instance($booking->customer_confirmed_at);

            if ($this->within($now, $confirmedAt->addMinutes(2), $confirmedAt->addMinutes(5))) {
                $rules[] = $this->rule(
                    recipient: $workerUser,
                    targetRole: 'worker',
                    canonicalType: 'cleaning.booking.worker_start_confirmation_reminder',
                    action: 'start_work',
                    requiredAction: 'start_work',
                    reminderKind: 'reminder',
                    severity: 'normal',
                    dueAt: $confirmedAt->addMinutes(2),
                    deadlineAt: $confirmedAt->addMinutes(5),
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }

            if ($this->within($now, $confirmedAt->addMinutes(5), $confirmedAt->addMinutes(30))) {
                $rules[] = $this->rule(
                    recipient: $workerUser,
                    targetRole: 'worker',
                    canonicalType: 'cleaning.booking.worker_start_confirmation_warning',
                    action: 'start_work',
                    requiredAction: 'start_work',
                    reminderKind: 'warning',
                    severity: 'high',
                    dueAt: $confirmedAt->addMinutes(5),
                    deadlineAt: $confirmedAt->addMinutes(5),
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }
        }

        return $rules;
    }

    private function within(CarbonImmutable $now, CarbonImmutable $from, CarbonImmutable $until): bool
    {
        return $now->greaterThanOrEqualTo($from) && $now->lessThan($until);
    }

    /** @return array<string, mixed> */
    private function rule(
        User $recipient,
        string $targetRole,
        string $canonicalType,
        string $action,
        string $requiredAction,
        string $reminderKind,
        string $severity,
        CarbonImmutable $dueAt,
        ?CarbonImmutable $deadlineAt,
        CarbonImmutable $scheduledAt,
        int $minutesUntilStart,
    ): array {
        return compact(
            'recipient',
            'targetRole',
            'canonicalType',
            'action',
            'requiredAction',
            'reminderKind',
            'severity',
            'dueAt',
            'deadlineAt',
            'scheduledAt',
            'minutesUntilStart',
        ) + ['assignment' => null];
    }
}

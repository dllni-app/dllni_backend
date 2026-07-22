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

final class CleaningBookingActionNotificationRuleEngine
{
    public function __construct(
        private readonly CleaningBookingScheduledAtResolver $scheduledAtResolver,
    ) {}

    /**
     * @return array<int, array{
     *     recipient:User,
     *     targetRole:string,
     *     assignment:?CleaningBookingWorkerAssignment,
     *     canonicalType:string,
     *     action:string,
     *     requiredAction:string,
     *     reminderKind:string,
     *     severity:string,
     *     dueAt:CarbonImmutable,
     *     deadlineAt:?CarbonImmutable,
     *     scheduledAt:CarbonImmutable,
     *     minutesUntilStart:int
     * }>
     */
    public function dueNotifications(CleaningBooking $booking, CarbonImmutable $now): array
    {
        $scheduledAt = $this->scheduledAtResolver->resolve($booking);
        if (! $scheduledAt instanceof CarbonImmutable) {
            return [];
        }

        $customer = $booking->customer;
        $assignments = $booking->workerAssignments
            ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => in_array(
                $this->assignmentStatus($assignment),
                CleaningBookingWorkerAssignmentStatus::activeValues(),
                true,
            ));

        $acceptedBeforeStart = $assignments->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => in_array(
            $this->assignmentStatus($assignment),
            [
                CleaningBookingWorkerAssignmentStatus::Accepted->value,
                CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
                CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
            ],
            true,
        ));

        $rules = [];
        $minutesUntilStart = (int) floor($now->diffInMinutes($scheduledAt, false));

        if ($this->within($now, $scheduledAt->subMinutes(60), $scheduledAt->subMinutes(30))) {
            if ($customer instanceof User && $assignments->isNotEmpty()) {
                $rules[] = $this->customerRule(
                    recipient: $customer,
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

            foreach ($assignments as $assignment) {
                if ($assignment->worker?->user instanceof User) {
                    $rules[] = $this->workerRule(
                        assignment: $assignment,
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
            }
        }

        if (
            $customer instanceof User
            && $this->within($now, $scheduledAt->subMinutes(10), $scheduledAt)
            && $assignments->count() < max(1, (int) ($booking->number_of_workers ?? 1))
        ) {
            $rules[] = $this->customerRule(
                recipient: $customer,
                canonicalType: 'cleaning.booking.team_incomplete_warning',
                action: 'review_team_status',
                requiredAction: 'review_team_status',
                reminderKind: 'warning',
                severity: 'high',
                dueAt: $scheduledAt->subMinutes(10),
                deadlineAt: $scheduledAt,
                scheduledAt: $scheduledAt,
                minutesUntilStart: $minutesUntilStart,
            );
        }

        foreach ($acceptedBeforeStart as $assignment) {
            if (! $assignment->worker?->user instanceof User || $assignment->started_travel_at !== null) {
                continue;
            }

            if ($this->within($now, $scheduledAt->subMinutes(30), $scheduledAt->subMinutes(10))) {
                $rules[] = $this->workerRule(
                    assignment: $assignment,
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
                $rules[] = $this->workerRule(
                    assignment: $assignment,
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

        foreach ($assignments as $assignment) {
            if (! $assignment->worker?->user instanceof User || $assignment->arrived_at !== null) {
                continue;
            }

            if ($this->within($now, $scheduledAt, $scheduledAt->addMinutes(5))) {
                $rules[] = $this->workerRule(
                    assignment: $assignment,
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
                $rules[] = $this->workerRule(
                    assignment: $assignment,
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
            $status === CleaningBookingStatus::AwaitingStartVerification
            && $customer instanceof User
            && $booking->customer_confirmed_at === null
        ) {
            $arrivedAt = $this->firstArrivalAt($booking, $assignments->all());
            if ($arrivedAt instanceof CarbonImmutable && $this->within($now, $arrivedAt->addMinutes(5), $arrivedAt->addMinutes(15))) {
                $rules[] = $this->customerRule(
                    recipient: $customer,
                    canonicalType: 'cleaning.booking.customer_verification_reminder',
                    action: 'verify_security_code',
                    requiredAction: 'verify_security_code',
                    reminderKind: 'reminder',
                    severity: 'normal',
                    dueAt: $arrivedAt->addMinutes(5),
                    deadlineAt: null,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }

            $securityCodeExpiresAt = $this->activeSecurityCodeExpiresAt($booking, $now);
            if (
                $securityCodeExpiresAt instanceof CarbonImmutable
                && $this->within($now, $securityCodeExpiresAt->subMinutes(2), $securityCodeExpiresAt)
            ) {
                $rules[] = $this->customerRule(
                    recipient: $customer,
                    canonicalType: 'cleaning.booking.customer_verification_warning',
                    action: 'verify_security_code',
                    requiredAction: 'verify_security_code',
                    reminderKind: 'warning',
                    severity: 'high',
                    dueAt: $securityCodeExpiresAt->subMinutes(2),
                    deadlineAt: $securityCodeExpiresAt,
                    scheduledAt: $scheduledAt,
                    minutesUntilStart: $minutesUntilStart,
                );
            }
        }

        if (
            $status === CleaningBookingStatus::AwaitingWorkerStartConfirmation
            && $booking->customer_confirmed_at instanceof CarbonInterface
        ) {
            $confirmedAt = CarbonImmutable::instance($booking->customer_confirmed_at);

            foreach ($assignments as $assignment) {
                if (! $assignment->worker?->user instanceof User || $assignment->start_approved_at !== null) {
                    continue;
                }

                if ($this->within($now, $confirmedAt->addMinutes(2), $confirmedAt->addMinutes(5))) {
                    $rules[] = $this->workerRule(
                        assignment: $assignment,
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
                    $rules[] = $this->workerRule(
                        assignment: $assignment,
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
        }

        return $rules;
    }

    private function within(CarbonImmutable $now, CarbonImmutable $from, CarbonImmutable $until): bool
    {
        return $now->greaterThanOrEqualTo($from) && $now->lessThan($until);
    }

    /** @param array<int, CleaningBookingWorkerAssignment> $assignments */
    private function firstArrivalAt(CleaningBooking $booking, array $assignments): ?CarbonImmutable
    {
        $timestamps = [];

        if ($booking->arrived_at instanceof CarbonInterface) {
            $timestamps[] = CarbonImmutable::instance($booking->arrived_at);
        }

        foreach ($assignments as $assignment) {
            if ($assignment->arrived_at instanceof CarbonInterface) {
                $timestamps[] = CarbonImmutable::instance($assignment->arrived_at);
            }
        }

        if ($timestamps === []) {
            return null;
        }

        usort($timestamps, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $timestamps[0];
    }

    private function activeSecurityCodeExpiresAt(CleaningBooking $booking, CarbonImmutable $now): ?CarbonImmutable
    {
        $expiresAt = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->orderByDesc('id')
            ->value('expires_at');

        return $expiresAt !== null
            ? CarbonImmutable::parse((string) $expiresAt, (string) config('cleaning_action_notifications.timezone', config('app.timezone')))
            : null;
    }

    private function assignmentStatus(CleaningBookingWorkerAssignment $assignment): string
    {
        return $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;
    }

    /** @return array<string, mixed> */
    private function customerRule(
        User $recipient,
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
            'canonicalType',
            'action',
            'requiredAction',
            'reminderKind',
            'severity',
            'dueAt',
            'deadlineAt',
            'scheduledAt',
            'minutesUntilStart',
        ) + [
            'targetRole' => 'customer',
            'assignment' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function workerRule(
        CleaningBookingWorkerAssignment $assignment,
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
        return [
            'recipient' => $assignment->worker->user,
            'targetRole' => 'worker',
            'assignment' => $assignment,
            'canonicalType' => $canonicalType,
            'action' => $action,
            'requiredAction' => $requiredAction,
            'reminderKind' => $reminderKind,
            'severity' => $severity,
            'dueAt' => $dueAt,
            'deadlineAt' => $deadlineAt,
            'scheduledAt' => $scheduledAt,
            'minutesUntilStart' => $minutesUntilStart,
        ];
    }
}

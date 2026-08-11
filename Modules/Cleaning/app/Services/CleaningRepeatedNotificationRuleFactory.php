<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningRepeatedNotificationRuleFactory
{
    /** @param array<string, mixed> $worker @param array<string, mixed> $repeat @param array<string, mixed> $extra */
    public function worker(
        array $worker,
        string $canonicalType,
        string $action,
        string $requiredAction,
        string $reminderKind,
        string $severity,
        ?CarbonImmutable $deadlineAt,
        CarbonImmutable $scheduledAt,
        int $minutesUntilStart,
        array $repeat,
        array $extra = [],
    ): array {
        return $this->make(
            recipient: $worker['recipient'],
            targetRole: 'worker',
            assignment: $worker['assignment'],
            canonicalType: $canonicalType,
            action: $action,
            requiredAction: $requiredAction,
            reminderKind: $reminderKind,
            severity: $severity,
            deadlineAt: $deadlineAt,
            scheduledAt: $scheduledAt,
            minutesUntilStart: $minutesUntilStart,
            repeat: $repeat,
            extra: ['workerId' => $worker['workerId']] + $extra,
        );
    }

    /** @param array<string, mixed> $repeat @param array<string, mixed> $extra */
    public function customer(
        User $recipient,
        string $canonicalType,
        string $action,
        string $requiredAction,
        string $reminderKind,
        string $severity,
        ?CarbonImmutable $deadlineAt,
        CarbonImmutable $scheduledAt,
        int $minutesUntilStart,
        array $repeat,
        array $extra = [],
    ): array {
        return $this->make(
            recipient: $recipient,
            targetRole: 'customer',
            assignment: null,
            canonicalType: $canonicalType,
            action: $action,
            requiredAction: $requiredAction,
            reminderKind: $reminderKind,
            severity: $severity,
            deadlineAt: $deadlineAt,
            scheduledAt: $scheduledAt,
            minutesUntilStart: $minutesUntilStart,
            repeat: $repeat,
            extra: $extra,
        );
    }

    /** @param array<string, mixed> $repeat @param array<string, mixed> $extra */
    private function make(
        User $recipient,
        string $targetRole,
        ?CleaningBookingWorkerAssignment $assignment,
        string $canonicalType,
        string $action,
        string $requiredAction,
        string $reminderKind,
        string $severity,
        ?CarbonImmutable $deadlineAt,
        CarbonImmutable $scheduledAt,
        int $minutesUntilStart,
        array $repeat,
        array $extra,
    ): array {
        return [
            'recipient' => $recipient,
            'targetRole' => $targetRole,
            'assignment' => $assignment,
            'canonicalType' => $canonicalType,
            'action' => $action,
            'requiredAction' => $requiredAction,
            'reminderKind' => $reminderKind,
            'severity' => $severity,
            'dueAt' => $repeat['dueAt'],
            'deadlineAt' => $deadlineAt,
            'scheduledAt' => $scheduledAt,
            'minutesUntilStart' => $minutesUntilStart,
        ] + $repeat + $extra;
    }
}

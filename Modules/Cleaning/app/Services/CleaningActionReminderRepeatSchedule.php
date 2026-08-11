<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;

final class CleaningActionReminderRepeatSchedule
{
    /**
     * @return array{dueAt:CarbonImmutable,occurrenceKey:string,repeatNumber:int,maxRepeats:int}|null
     */
    public function occurrence(
        string $policy,
        CarbonImmutable $now,
        CarbonImmutable $firstDueAt,
        CarbonImmutable $until,
    ): ?array {
        if ($now->lessThan($firstDueAt) || ! $now->lessThan($until)) {
            return null;
        }

        $settings = (array) config("cleaning_action_notifications.repeat_policies.{$policy}", []);
        $intervalMinutes = max(1, (int) ($settings['interval_minutes'] ?? 5));
        $maxOccurrences = max(1, (int) ($settings['max_occurrences'] ?? 1));
        $elapsedSeconds = max(0, $now->getTimestamp() - $firstDueAt->getTimestamp());
        $occurrenceIndex = intdiv($elapsedSeconds, $intervalMinutes * 60);

        if ($occurrenceIndex >= $maxOccurrences) {
            return null;
        }

        $dueAt = $firstDueAt->addMinutes($occurrenceIndex * $intervalMinutes);

        return [
            'dueAt' => $dueAt,
            'occurrenceKey' => "repeat:{$policy}:{$occurrenceIndex}",
            'repeatNumber' => $occurrenceIndex + 1,
            'maxRepeats' => $maxOccurrences,
        ];
    }

    public function configuredUntil(string $policy, CarbonImmutable $firstDueAt): CarbonImmutable
    {
        $settings = (array) config("cleaning_action_notifications.repeat_policies.{$policy}", []);
        $intervalMinutes = max(1, (int) ($settings['interval_minutes'] ?? 5));
        $maxOccurrences = max(1, (int) ($settings['max_occurrences'] ?? 1));

        return $firstDueAt->addMinutes($intervalMinutes * $maxOccurrences);
    }
}

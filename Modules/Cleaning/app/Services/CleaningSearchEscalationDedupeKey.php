<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Stable dedupe identity for each booking's six-hour customer escalation.
 * Re-sending within the same search window must not generate another notice.
 * A customer-initiated retry starts a distinct window with its own notice.
 */
final class CleaningSearchEscalationDedupeKey
{
    public function make(int $bookingId, CarbonInterface $searchStartedAt): string
    {
        if ($bookingId < 1) {
            throw new InvalidArgumentException('A persisted booking ID is required.');
        }

        return hash('sha256', 'cleaning.search.expired|'.$bookingId.'|'.$searchStartedAt->toIso8601String());
    }
}

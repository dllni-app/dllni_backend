<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;

/**
 * Pure V3 parent projection; never mutates a completed sibling or writes a
 * legacy parent. The legacy-compatible status is a READ projection only.
 */
final class CleaningV3VisitStatusProjector
{
    /**
     * @param  list<string>  $visitStatuses
     * @return array{status:string,legacyStatus:string,completed:int,cancelled:int,skipped:int,open:int,superseded:int,total:int}
     */
    public function project(array $visitStatuses): array
    {
        $known = array_column(CleaningBookingSessionStatus::cases(), 'value');
        $counts = array_fill_keys($known, 0);
        foreach ($visitStatuses as $status) {
            if (! is_string($status) || ! in_array($status, $known, true)) {
                throw new InvalidArgumentException('Unknown V3 persisted visit status.');
            }
            $counts[$status]++;
        }

        $superseded = $counts['superseded'];
        $total = count($visitStatuses) - $superseded;
        if ($total < 1) {
            throw new InvalidArgumentException('A V3 booking requires at least one current visit.');
        }

        $completed = $counts['completed'];
        $cancelled = $counts['cancelled'];
        $skipped = $counts['skipped'];
        $open = $total - $completed - $cancelled - $skipped;

        if ($open === 0) {
            $status = $completed === $total ? 'completed'
                : ($completed > 0 ? 'partially_completed' : 'cancelled');
        } elseif ($counts['under_dispute'] > 0) {
            $status = 'under_dispute';
        } elseif ($counts['time_extension_requested'] > 0) {
            $status = 'time_extension_requested';
        } elseif ($counts['awaiting_customer_completion'] > 0) {
            $status = 'awaiting_customer_completion';
        } elseif ($counts['in_progress'] > 0 || $counts['paused'] > 0) {
            $status = 'in_progress';
        } elseif ($counts['awaiting_start_verification'] > 0) {
            $status = 'awaiting_start_verification';
        } elseif ($counts['awaiting_worker_start_confirmation'] > 0) {
            $status = 'awaiting_worker_start_confirmation';
        } elseif ($counts['worker_assigned'] > 0) {
            $status = 'worker_assigned';
        } else {
            $status = 'pending';
        }

        return [
            'status' => $status,
            'legacyStatus' => $status === 'partially_completed' ? 'completed' : $status,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'skipped' => $skipped,
            'open' => $open,
            'superseded' => $superseded,
            'total' => $total,
        ];
    }
}

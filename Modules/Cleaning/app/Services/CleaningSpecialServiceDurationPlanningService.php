<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Collection;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;

final class CleaningSpecialServiceDurationPlanningService
{
    /**
     * Tasks assigned to the same worker run sequentially. Distinct workers
     * operate in parallel, while unassigned work is conservatively sequenced.
     * This is a planning estimate, not an authoritative work timer or a bill.
     *
     * @return array<string,mixed>
     */
    public function forBooking(CleaningBooking $booking): array
    {
        $lines = $booking->relationLoaded('specialServices')
            ? $booking->specialServices->sortBy('id')
            : $booking->specialServices()->with('specialService')->orderBy('id')->get();
        $lines->loadMissing('specialService');
        $sessions = $lines->groupBy(static fn (CleaningBookingSpecialService $line): string =>
            (string) ($line->cleaning_booking_session_id ?? 0));

        $plans = $sessions->map(function (Collection $subset, string $sessionId): array {
            $plan = $this->forLines($subset);
            return ['sessionId' => (int) $sessionId, ...$plan];
        })->values()->all();

        return [
            'sessionPlans' => $plans,
            'totalWorkMinutes' => array_sum(array_column($plans, 'totalWorkMinutes')),
            // Different visits happen on different days, so do not conflate a
            // series' total time with a single-day completion estimate.
            'maxSingleSessionMinutes' => $plans === [] ? 0 : max(array_column($plans, 'estimatedElapsedMinutes')),
            'strategy' => 'sequential_per_worker_parallel_across_workers',
        ];
    }

    /** @param Collection<int,CleaningBookingSpecialService> $lines @return array<string,mixed> */
    public function forLines(Collection $lines): array
    {
        $workerLoads = [];
        $unassignedMinutes = 0;
        $tasks = [];

        foreach ($lines as $line) {
            $minutesPerUnit = max(0, (int) ($line->specialService?->estimated_duration_minutes ?? 0));
            $quantity = (bool) config('cleaning_specialist_planning.duration_per_quantity', true)
                ? max(0, (float) $line->quantity) : 1.0;
            $duration = (int) ceil($minutesPerUnit * $quantity);
            $workerId = $line->assigned_worker_id !== null ? (int) $line->assigned_worker_id : null;

            if ($workerId === null) {
                $unassignedMinutes += $duration;
            } else {
                $workerLoads[$workerId] = ($workerLoads[$workerId] ?? 0) + $duration;
            }

            $tasks[] = [
                'lineId' => (int) $line->id,
                'serviceId' => (int) $line->cleaning_special_service_id,
                'workerId' => $workerId,
                'durationMinutes' => $duration,
            ];
        }

        ksort($workerLoads);
        $total = array_sum(array_column($tasks, 'durationMinutes'));
        $parallelMinutes = $workerLoads === [] ? 0 : max($workerLoads);

        return [
            'totalWorkMinutes' => $total,
            'estimatedElapsedMinutes' => $parallelMinutes + $unassignedMinutes,
            'unassignedWorkMinutes' => $unassignedMinutes,
            'workerLoadsMinutes' => $workerLoads,
            'parallelWorkerCount' => count($workerLoads),
            'tasks' => $tasks,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/**
 * Bounded, read-only capacity feasibility for independent, indivisible tasks.
 *
 * The caller must supply effort calculated from an approved server catalog,
 * never from customer input. Tasks with dependencies, shared resources or
 * simultaneous multi-worker requirements fail closed until a real scheduler
 * can verify them. This service does not reserve workers, create visits,
 * approve prices or authorize a financial operation.
 */
final class CleaningV3WorkloadCapacityPlanner
{
    private const MAX_WORKER_MINUTES = 480;

    private const MAX_TASKS = 40;

    private const MAX_VISITS_PER_APPOINTMENT = 10;

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function estimate(
        array $tasks,
        int $maximumWorkers = 20,
        int $windowMinutes = self::MAX_WORKER_MINUTES,
        int $searchBudget = 100000,
    ): array {
        if ($maximumWorkers < 1 || $maximumWorkers > 20
            || $windowMinutes < 1 || $windowMinutes > self::MAX_WORKER_MINUTES
            || $searchBudget < 1 || $searchBudget > 1000000) {
            throw new CleaningV3CapacityException('INVALID_CAPACITY_LIMITS');
        }
        if (! array_is_list($tasks) || $tasks === []) {
            throw new CleaningV3CapacityException('INVALID_WORKLOAD_TASKS');
        }
        if (count($tasks) > self::MAX_TASKS) {
            throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
        }

        $normalized = [];
        $keys = [];
        $totalMinutes = 0;
        $largeTasks = 0;

        foreach ($tasks as $index => $task) {
            if (! is_array($task) || array_is_list($task)) {
                throw new CleaningV3CapacityException('INVALID_WORKLOAD_TASK', 422, "tasks.$index");
            }

            $unknown = array_diff(array_keys($task), [
                'taskKey', 'durationMinutes', 'splittable', 'dependencies',
                'resourceKeys', 'requiredWorkers',
            ]);
            if ($unknown !== []
                || ($task['splittable'] ?? false) !== false
                || ($task['dependencies'] ?? []) !== []
                || ($task['resourceKeys'] ?? []) !== []
                || ($task['requiredWorkers'] ?? 1) !== 1) {
                throw new CleaningV3CapacityException('CAPACITY_RULES_NOT_IMPLEMENTED', 503, "tasks.$index");
            }

            $key = $task['taskKey'] ?? null;
            $duration = $task['durationMinutes'] ?? null;
            if (! is_string($key) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D', $key) !== 1
                || isset($keys[$key])) {
                throw new CleaningV3CapacityException('INVALID_OR_DUPLICATE_TASK_KEY', 422, "tasks.$index.taskKey");
            }
            if (! is_int($duration) || $duration < 1 || $duration > $windowMinutes) {
                throw new CleaningV3CapacityException('INVALID_TASK_DURATION', 422, "tasks.$index.durationMinutes");
            }

            $keys[$key] = true;
            $totalMinutes += $duration;
            if ($duration > intdiv($windowMinutes, 2)) {
                $largeTasks++;
            }
            $normalized[] = ['taskKey' => $key, 'durationMinutes' => $duration];
        }

        usort($normalized, static fn (array $a, array $b): int => [
            -$a['durationMinutes'], $a['taskKey'],
        ] <=> [
            -$b['durationMinutes'], $b['taskKey'],
        ]);

        $minimum = max(
            1,
            (int) ceil($totalMinutes / $windowMinutes),
            $largeTasks,
        );
        $upper = min(count($normalized), $maximumWorkers);
        if ($minimum > $upper) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }

        $visitedNodes = 0;
        for ($workerCount = $minimum; $workerCount <= $upper; $workerCount++) {
            $workerLoads = array_fill(0, $workerCount, 0);
            $workerAssignments = array_fill(0, $workerCount, []);
            if (! $this->assign(
                $normalized,
                0,
                $windowMinutes,
                $workerLoads,
                $workerAssignments,
                $visitedNodes,
                $searchBudget,
            )) {
                continue;
            }

            return [
                'estimatedWorkers' => $workerCount,
                'totalEffortMinutes' => $totalMinutes,
                'windowMinutes' => $windowMinutes,
                'workerLoads' => array_map(
                    static fn (int $index): array => [
                        'workerSlot' => $index + 1,
                        'taskKeys' => $workerAssignments[$index],
                        'effortMinutes' => $workerLoads[$index],
                    ],
                    array_keys($workerLoads),
                ),
                'previewOnly' => true,
                'requiresWorkerAvailabilityCheck' => true,
                'requiresGeographicCheck' => true,
                'canQuote' => false,
                'canCreateBooking' => false,
            ];
        }

        throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
    }

    /**
     * Pure duration segmentation for events; date placement, crew distinctness,
     * availability and pricing are separate mandatory server-side checks.
     *
     * @return list<int> Visit durations in minutes.
     */
    public function splitEventDuration(int $totalMinutes): array
    {
        $minimum = 60;
        $step = 30;
        $maximum = self::MAX_WORKER_MINUTES;

        if ($totalMinutes < $minimum || $totalMinutes > $maximum * self::MAX_VISITS_PER_APPOINTMENT) {
            throw new CleaningV3CapacityException('EVENT_DURATION_OUT_OF_RANGE', 422, 'durationMinutes');
        }
        if ($totalMinutes % $step !== 0) {
            throw new CleaningV3CapacityException('INVALID_EVENT_DURATION_STEP', 422, 'durationMinutes');
        }

        $segments = array_fill(0, intdiv($totalMinutes, $maximum), $maximum);
        $remainder = $totalMinutes % $maximum;
        if ($remainder !== 0) {
            if ($remainder < $minimum) {
                $borrow = $minimum - $remainder;
                $last = count($segments) - 1;
                if ($last < 0 || $minimum > $segments[$last] - $borrow || $borrow % $step !== 0) {
                    throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
                }
                $segments[$last] -= $borrow;
                $remainder += $borrow;
            }
            $segments[] = $remainder;
        }

        if (count($segments) > self::MAX_VISITS_PER_APPOINTMENT) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }

        return $segments;
    }

    /**
     * Depth-first exact partition search: for independent tasks, a valid bin
     * packing is a valid sequential plan per worker within the visit window.
     * Symmetric worker slots are pruned; budget exhaustion is never "feasible".
     *
     * @param  list<array{taskKey:string,durationMinutes:int}>  $tasks
     * @param  list<int>  $loads
     * @param  list<list<string>>  $assignments
     */
    private function assign(
        array $tasks,
        int $taskIndex,
        int $windowMinutes,
        array &$loads,
        array &$assignments,
        int &$visitedNodes,
        int $searchBudget,
    ): bool {
        if ($taskIndex === count($tasks)) {
            return true;
        }

        $task = $tasks[$taskIndex];
        $seenLoads = [];
        foreach ($loads as $worker => $load) {
            if ($load + $task['durationMinutes'] > $windowMinutes
                || isset($seenLoads[$load])) {
                continue;
            }
            $seenLoads[$load] = true;

            $visitedNodes++;
            if ($visitedNodes > $searchBudget) {
                throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
            }

            $loads[$worker] += $task['durationMinutes'];
            $assignments[$worker][] = $task['taskKey'];

            if ($this->assign($tasks, $taskIndex + 1, $windowMinutes, $loads, $assignments, $visitedNodes, $searchBudget)) {
                return true;
            }

            $loads[$worker] -= $task['durationMinutes'];
            array_pop($assignments[$worker]);
        }

        return false;
    }
}

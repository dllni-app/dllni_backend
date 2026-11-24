<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/** Bounded deterministic packing of indivisible work into visit/worker time bins. */
final class CleaningV3VisitTaskAllocator
{
    public function allocate(array $tasks, array $visits, int &$budget): array
    {
        if ($tasks === [] || count($tasks) > 40 || $visits === [] || count($visits) > 10) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }
        if (count($tasks) < count($visits)) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }
        usort($tasks, static fn (array $a, array $b): int => [-$a['durationMinutes'], $a['taskKey']] <=> [-$b['durationMinutes'], $b['taskKey']]);
        $bins = [];
        $visitTasks = array_fill(0, count($visits), []);
        foreach ($visits as $i => $visit) {
            // Empty worker slots beyond task count cannot improve feasibility.
            for ($j = 0; $j < min($visit['requiredWorkers'], count($tasks)); $j++) {
                $bins[] = ['visit' => $i, 'left' => $visit['durationMinutes']];
            }
        }
        if (array_sum(array_column($tasks, 'durationMinutes')) > array_sum(array_column($bins, 'left'))) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }
        if (! $this->place($tasks, 0, $bins, $visitTasks, $budget)) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }

        return $visitTasks;
    }

    private function place(array $tasks, int $index, array &$bins, array &$visitTasks, int &$budget): bool
    {
        if (--$budget < 0) {
            throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
        }
        $empty = count(array_filter($visitTasks, static fn ($rows) => $rows === []));
        $remaining = count($tasks) - $index;
        if ($remaining < $empty) {
            return false;
        }
        if ($remaining === 0) {
            return $empty === 0;
        }

        $task = $tasks[$index];
        $seen = [];
        // Prefer an empty visit so proposed visits never silently become empty
        // paid trips. Search all other nonsymmetric slots on backtracking.
        $order = array_keys($bins);
        usort($order, static fn ($a, $b) => [
            $visitTasks[$bins[$a]['visit']] !== [], $bins[$a]['left'], $a,
        ] <=> [
            $visitTasks[$bins[$b]['visit']] !== [], $bins[$b]['left'], $b,
        ]);
        foreach ($order as $slot) {
            $bin = $bins[$slot];
            $i = $bin['visit'];
            $key = $i.':'.$bin['left'];
            if ($bin['left'] < $task['durationMinutes'] || isset($seen[$key])
                || ($remaining === $empty && $visitTasks[$i] !== [])) {
                continue;
            }
            $seen[$key] = true;
            $bins[$slot]['left'] -= $task['durationMinutes'];
            $visitTasks[$i][] = $task;
            if ($this->place($tasks, $index + 1, $bins, $visitTasks, $budget)) {
                return true;
            }
            array_pop($visitTasks[$i]);
            $bins[$slot]['left'] += $task['durationMinutes'];
        }

        return false;
    }
}

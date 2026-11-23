<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Server-catalog-only, read-only V3 capacity preview.
 *
 * Each count/piece is an independent non-splittable task. Square metres,
 * metres, hourly and fixed units are atomic lines unless a separately
 * approved decomposition rule is provided. Unsupported long jobs fail
 * closed rather than being silently split into unpriced visits.
 */
final class CleaningV3PublishedWorkloadPreviewService
{
    public function __construct(
        private readonly CleaningV3PublishedTariffEvaluator $tariffs,
        private readonly CleaningV3WorkloadCapacityPlanner $capacity,
    ) {}

    /**
     * @param  list<array{itemKey:string,cleaningMode:string,scopeKey:string,quantity:string}>  $lines
     * @return array<string,mixed>
     */
    public function preview(
        CleaningV3CatalogVersion $catalog,
        string $publishedDigest,
        array $lines,
        int $maximumWorkers = 20,
        int $windowMinutes = 480,
    ): array {
        $priced = $this->tariffs->evaluate($catalog, $publishedDigest, $lines);
        $tasks = [];
        foreach ($priced['lines'] as $row) {
            $taskPrefix = $row['itemKey'].':'.$row['cleaningMode'].':'.$row['scopeKey'];
            if (in_array($row['measurementMode'], ['count', 'piece'], true)) {
                $units = (int) $row['quantity'];
                if ($units > 40) {
                    throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
                }
                $unitMinutes = $this->roundMinutes($row['unitEffortMinutes']);
                for ($i = 1; $i <= $units; $i++) {
                    $tasks[] = [
                        'taskKey' => $taskPrefix.':'.$i,
                        'durationMinutes' => $unitMinutes,
                        'splittable' => false,
                    ];
                }
            } else {
                $tasks[] = [
                    'taskKey' => $taskPrefix.':line',
                    'durationMinutes' => $this->roundMinutes($row['lineEffortMinutes']),
                    'splittable' => false,
                ];
            }

            if (count($tasks) > 40) {
                throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
            }
        }

        $result = $this->capacity->estimate(
            $tasks,
            $maximumWorkers,
            $windowMinutes,
        );

        return [
            'catalogVersionKey' => $priced['catalogVersionKey'],
            'catalogRevision' => $priced['catalogRevision'],
            'currency' => 'SYP',
            'estimatedBaseAmountSyp' => $priced['estimatedBaseAmountSyp'],
            'estimatedEffortMinutes' => $priced['estimatedEffortMinutes'],
            'capacity' => $result,
            'requiresActualWorkerConflictCheck' => true,
            'requiresGeographicCheck' => true,
            'requiresApprovedCommercialPricing' => true,
            'canQuote' => false,
            'canBook' => false,
            'canSettle' => false,
        ];
    }

    private function roundMinutes(string $decimal): int
    {
        if (! extension_loaded('bcmath')
            || bccomp($decimal, '0', 2) <= 0
            || bccomp($decimal, '480', 2) > 0) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }

        $rounded = bcadd($decimal, '0', 0);

        return (int) (bccomp($decimal, $rounded, 2) > 0
            ? bcadd($rounded, '1', 0)
            : $rounded);
    }
}

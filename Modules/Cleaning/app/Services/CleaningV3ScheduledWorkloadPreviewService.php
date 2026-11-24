<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Each appointment represents the complete selected service once. Its visits
 * share one immutable tariff task set; repeated appointments repeat the service.
 * The allocation is planning evidence, never a worker assignment or a quote.
 */
final class CleaningV3ScheduledWorkloadPreviewService
{
    public function __construct(
        private readonly CleaningV3PublishedWorkloadPreviewService $workload,
        private readonly CleaningV3VisitTaskAllocator $allocator,
        private readonly CleaningV3WorkloadCapacityPlanner $capacity,
    ) {}

    public function check(
        CleaningV3CatalogVersion $catalog,
        string $digest,
        array $answers,
        mixed $mode,
        mixed $scopes,
        mixed $propertyKey,
        array $appointments,
    ): array {
        $prepared = $this->workload->prepareForm($catalog, $digest, $answers, $mode, $scopes, $propertyKey);
        $tasks = $prepared['tasks'];
        $equipment = app(CleaningV3EquipmentPreviewService::class)->scheduled($prepared['equipmentRequests'], $appointments);
        $labels = $catalog->items->pluck('label_ar', 'item_key');
        $cache = [];
        $capacityCache = [];
        $budget = 100000;
        $visits = [];
        $total = '0.00';
        foreach ($appointments as $appointment) {
            $shape = json_encode(array_map(static fn (array $visit) => [
                $visit['requiredWorkers'], $visit['durationMinutes'],
            ], $appointment['visits']), JSON_THROW_ON_ERROR);
            try {
                $allocated = $cache[$shape] ??= $this->allocator->allocate($tasks, $appointment['visits'], $budget);
                $appointmentAmount = '0.00';
                foreach ($appointment['visits'] as $i => $visit) {
                    $taskRows = $allocated[$i];
                    $capacityTasks = array_map(static fn (array $task) => [
                        'taskKey' => $task['taskKey'], 'durationMinutes' => $task['durationMinutes'],
                    ], $taskRows);
                    $capacityKey = hash('sha256', json_encode([$visit['requiredWorkers'], $visit['durationMinutes'], $capacityTasks], JSON_THROW_ON_ERROR));
                    if (! isset($capacityCache[$capacityKey])) {
                        if (count($capacityCache) >= 90) {
                            throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
                        }
                        // Bound the second search as well; no approximate pass.
                        $capacityCache[$capacityKey] = $this->capacity->estimate(
                            $capacityTasks, $visit['requiredWorkers'], $visit['durationMinutes'], 10000,
                        );
                    }
                    $result = $capacityCache[$capacityKey];
                    $amount = '0.00';
                    $groups = [];
                    foreach ($taskRows as $task) {
                        $amount = bcadd($amount, $task['amountSyp'], 2);
                        $key = $task['itemKey'].'|'.$task['scopeKey'];
                        $group = $groups[$key] ?? [
                            'itemKey' => $task['itemKey'],
                            'label' => (string) $labels->get($task['itemKey'], $task['itemKey']),
                            'scopeKey' => $task['scopeKey'],
                            'measurementMode' => $task['measurementMode'],
                            'quantity' => '0.00',
                            'effortMinutes' => 0,
                            'estimatedBaseAmountSyp' => '0.00',
                        ];
                        $group['quantity'] = bcadd($group['quantity'], $task['quantity'], 2);
                        $group['effortMinutes'] += $task['durationMinutes'];
                        $group['estimatedBaseAmountSyp'] = bcadd($group['estimatedBaseAmountSyp'], $task['amountSyp'], 2);
                        $groups[$key] = $group;
                    }
                    ksort($groups, SORT_STRING);
                    $appointmentAmount = bcadd($appointmentAmount, $amount, 2);
                    $visits[] = [
                        'appointmentKey' => $appointment['appointmentKey'],
                        'occurrenceKey' => $visit['occurrenceKey'],
                        'requestedWorkers' => $visit['requiredWorkers'],
                        'windowMinutes' => $visit['durationMinutes'],
                        'minimumWorkers' => $result['estimatedWorkers'],
                        'effortMinutes' => $result['totalEffortMinutes'],
                        'estimatedBaseAmountSyp' => $amount,
                        'taskGroups' => array_values($groups),
                    ];
                }
                if (bccomp($appointmentAmount, $prepared['estimatedBaseAmountSyp'], 2) !== 0) {
                    throw new CleaningV3CapacityException('V3_TASK_PRICE_CONSERVATION_FAILED', 503);
                }
                $total = bcadd($total, $appointmentAmount, 2);
            } catch (CleaningV3CapacityException $exception) {
                throw new CleaningV3CapacityException(
                    $exception->reasonCode === 'INVALID_TASK_DURATION' ? 'CAPACITY_INFEASIBLE' : $exception->reasonCode,
                    $exception->httpStatus, 'appointments.'.$appointment['appointmentKey'],
                );
            }
        }

        return [
            'checked' => true,
            'previewOnly' => true,
            'requiresActualWorkerConflictCheck' => true,
            'currency' => 'SYP',
            'formFingerprint' => $prepared['formFingerprint'],
            'totalEstimatedBaseAmountSyp' => $total,
            'equipmentPreview' => $equipment,
            'visits' => $visits,
        ];
    }
}

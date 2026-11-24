<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Verify one complete form per appointment against the proposed visit's crew
 * and active window. A split appointment needs explicit task-to-visit allocation
 * instead of silently duplicating or spreading its priced work.
 */
final class CleaningV3ScheduledWorkloadPreviewService
{
    public function __construct(private readonly CleaningV3PublishedWorkloadPreviewService $workload) {}

    public function check(
        CleaningV3CatalogVersion $catalog,
        string $digest,
        array $answers,
        mixed $mode,
        mixed $scopes,
        mixed $propertyKey,
        array $appointments,
    ): array {
        $cache = [];
        $visits = [];
        foreach ($appointments as $appointment) {
            if (count($appointment['visits']) !== 1) {
                throw new CleaningV3CapacityException('V3_SPLIT_TASK_ALLOCATION_REQUIRED', 422, 'appointments');
            }
            $visit = $appointment['visits'][0];
            $key = $visit['requiredWorkers'].':'.$visit['durationMinutes'];
            if (! isset($cache[$key])) {
                if (count($cache) >= 10) {
                    throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503, 'appointments');
                }
                try {
                    $cache[$key] = $this->workload->previewForm(
                        $catalog, $digest, $answers, $mode, $scopes, $propertyKey,
                        $visit['requiredWorkers'], $visit['durationMinutes'],
                    );
                } catch (CleaningV3CapacityException $exception) {
                    throw new CleaningV3CapacityException(
                        $exception->reasonCode === 'INVALID_TASK_DURATION' ? 'CAPACITY_INFEASIBLE' : $exception->reasonCode,
                        $exception->httpStatus, 'appointments.'.$appointment['appointmentKey'],
                    );
                }
            }
            $result = $cache[$key];
            $visits[] = [
                'occurrenceKey' => $visit['occurrenceKey'],
                'requestedWorkers' => $visit['requiredWorkers'],
                'windowMinutes' => $visit['durationMinutes'],
                'minimumWorkers' => $result['capacity']['estimatedWorkers'],
            ];
        }

        return [
            'checked' => true,
            'previewOnly' => true,
            'requiresActualWorkerConflictCheck' => true,
            'visits' => $visits,
        ];
    }
}

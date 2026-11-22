<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3CapacityException;
use Modules\Cleaning\Services\CleaningV3WorkloadCapacityPlanner;

function v3IndependentTasks(array $durations): array
{
    return array_map(
        static fn (int $index, int $duration): array => [
            'taskKey' => 'task-'.($index + 1),
            'durationMinutes' => $duration,
        ],
        array_keys($durations),
        $durations,
    );
}

test('three indivisible 250 minute tasks require three workers despite 750 total minutes', function (): void {
    $result = (new CleaningV3WorkloadCapacityPlanner)->estimate(v3IndependentTasks([250, 250, 250]));

    expect($result['estimatedWorkers'])->toBe(3)
        ->and($result['totalEffortMinutes'])->toBe(750)
        ->and($result['windowMinutes'])->toBe(480)
        ->and(array_column($result['workerLoads'], 'effortMinutes'))->toBe([250, 250, 250])
        ->and($result['previewOnly'])->toBeTrue()
        ->and($result['canQuote'])->toBeFalse()
        ->and($result['canCreateBooking'])->toBeFalse();
});

test('capacity search finds an exact feasible packing, not just the theoretical lower bound', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;
    $result = $planner->estimate(v3IndependentTasks(array_fill(0, 10, 170)));
    expect($result['estimatedWorkers'])->toBe(5)
        ->and(array_sum(array_column($result['workerLoads'], 'effortMinutes')))->toBe(1700)
        ->and(max(array_column($result['workerLoads'], 'effortMinutes')))->toBe(340);

    $two = $planner->estimate(v3IndependentTasks([240, 240, 240, 240]));
    expect($two['estimatedWorkers'])->toBe(2)
        ->and(array_column($two['workerLoads'], 'effortMinutes'))->toBe([480, 480]);
});

test('capacity report is stable across reordered server tasks', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;
    $tasks = v3IndependentTasks([150, 250, 80, 300, 150]);
    $forward = $planner->estimate($tasks);
    $backward = $planner->estimate(array_reverse($tasks));

    expect($backward)->toEqual($forward)
        ->and($forward['requiresWorkerAvailabilityCheck'])->toBeTrue()
        ->and($forward['requiresGeographicCheck'])->toBeTrue();
});

test('infeasible crew ceiling and oversized indivisible tasks are rejected', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;

    expect(fn () => $planner->estimate(v3IndependentTasks([250, 250, 250]), maximumWorkers: 2))
        ->toThrow(CleaningV3CapacityException::class, 'CAPACITY_INFEASIBLE');
    expect(fn () => $planner->estimate(v3IndependentTasks([481])))
        ->toThrow(CleaningV3CapacityException::class, 'INVALID_TASK_DURATION');
    expect(fn () => $planner->estimate(v3IndependentTasks([120]), windowMinutes: 100))
        ->toThrow(CleaningV3CapacityException::class, 'INVALID_TASK_DURATION');
});

test('unsupported dependencies, resource locks and simultaneous worker needs fail closed', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;
    foreach ([
        ['dependencies' => ['before']],
        ['resourceKeys' => ['industrial-vacuum']],
        ['requiredWorkers' => 2],
        ['splittable' => true],
        ['unknownRule' => 'ignore-me'],
    ] as $requirement) {
        $tasks = [['taskKey' => 'task-a', 'durationMinutes' => 60, ...$requirement]];
        try {
            $planner->estimate($tasks);
            test()->fail('Unsupported workload requirement was silently accepted.');
        } catch (CleaningV3CapacityException $exception) {
            expect($exception->reasonCode)->toBe('CAPACITY_RULES_NOT_IMPLEMENTED')
                ->and($exception->httpStatus)->toBe(503);
        }
    }
});

test('malformed and duplicate task keys cannot be silently priced', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;
    foreach ([
        [['taskKey' => 'same', 'durationMinutes' => 60], ['taskKey' => 'same', 'durationMinutes' => 60]],
        [['taskKey' => '', 'durationMinutes' => 60]],
    ] as $tasks) {
        expect(fn () => $planner->estimate($tasks))
            ->toThrow(CleaningV3CapacityException::class, 'INVALID_OR_DUPLICATE_TASK_KEY');
    }
    expect(fn () => $planner->estimate([]))
        ->toThrow(CleaningV3CapacityException::class, 'INVALID_WORKLOAD_TASKS');
});

test('exhausted search budget reports unavailable rather than fabricating worker capacity', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;
    try {
        $planner->estimate(v3IndependentTasks([160, 160, 160, 160]), searchBudget: 1);
        test()->fail('A truncated search must not assert feasibility.');
    } catch (CleaningV3CapacityException $exception) {
        expect($exception->reasonCode)->toBe('CAPACITY_CHECK_UNAVAILABLE')
            ->and($exception->httpStatus)->toBe(503);
    }
});

test('twelve hour event splits into independent eight and four hour visits', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;

    expect($planner->splitEventDuration(720))->toBe([480, 240])
        ->and($planner->splitEventDuration(480))->toBe([480])
        ->and($planner->splitEventDuration(510))->toBe([450, 60])
        ->and($planner->splitEventDuration(540))->toBe([480, 60])
        ->and($planner->splitEventDuration(90))->toBe([90]);

    expect(array_sum($planner->splitEventDuration(720)))->toBe(720);
});

test('events with too little time, incorrect step or more than ten visits are rejected', function (): void {
    $planner = new CleaningV3WorkloadCapacityPlanner;

    expect(fn () => $planner->splitEventDuration(30))
        ->toThrow(CleaningV3CapacityException::class, 'EVENT_DURATION_OUT_OF_RANGE');
    expect(fn () => $planner->splitEventDuration(75))
        ->toThrow(CleaningV3CapacityException::class, 'INVALID_EVENT_DURATION_STEP');
    expect(fn () => $planner->splitEventDuration(4830))
        ->toThrow(CleaningV3CapacityException::class, 'EVENT_DURATION_OUT_OF_RANGE');
});

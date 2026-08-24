<?php

declare(strict_types=1);

use Modules\User\Support\CleaningWorkerCapacity;

it('requires enough workers to keep estimated work within eight hours per worker', function (
    float $estimatedHours,
    int $expectedWorkers,
): void {
    expect(CleaningWorkerCapacity::requiredWorkers($estimatedHours))->toBe($expectedWorkers);
})->with([
    [0.0, 1],
    [3.0, 1],
    [8.0, 1],
    [8.1, 2],
    [13.0, 2],
    [16.0, 2],
    [16.1, 3],
]);

it('exposes the worker capacity metadata', function (): void {
    expect(CleaningWorkerCapacity::payload(13.0))->toBe([
        'requiredWorkers' => 2,
        'maxHoursPerWorker' => 8.0,
    ]);
});

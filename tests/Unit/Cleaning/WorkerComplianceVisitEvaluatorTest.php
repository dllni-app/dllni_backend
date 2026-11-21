<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Cleaning\Services\WorkerComplianceVisitEvaluator;

beforeEach(function (): void {
    config()->set('cleaning.worker_compliance.maximum_accuracy_m', 50);
    config()->set('cleaning.worker_compliance.proximity_radius_m', 100);
    config()->set('cleaning.worker_compliance.minimum_sample_count', 4);
    config()->set('cleaning.worker_compliance.minimum_dwell_seconds', 600);
    config()->set('cleaning.worker_compliance.maximum_sample_gap_seconds', 240);
    config()->set('cleaning.worker_compliance.maximum_dwell_path_m', 300);
});

function complianceSamplesAtOffsets(array $minuteOffsets, float $latitude = 33.5138, float $longitude = 36.2765, float $accuracy = 10): array
{
    return array_map(static fn (int $offset): array => [
        'captured_at' => CarbonImmutable::now()->subMinutes($offset),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy_m' => $accuracy,
    ], $minuteOffsets);
}

it('requires four accurate fixes spanning ten minutes with no gap over four minutes', function (): void {
    $evaluator = new WorkerComplianceVisitEvaluator();

    $run = $evaluator->qualifyingRun(complianceSamplesAtOffsets([12, 8, 4, 0]), 33.5138, 36.2765);

    expect($run)->not->toBeNull()
        ->and($run['sample_count'])->toBe(4)
        ->and($run['ended_at']->getTimestamp() - $run['started_at']->getTimestamp())->toBe(720);
});

it('rejects short dwell and gaps longer than four minutes', function (): void {
    $evaluator = new WorkerComplianceVisitEvaluator();

    expect($evaluator->qualifyingRun(complianceSamplesAtOffsets([9, 6, 3, 0]), 33.5138, 36.2765))->toBeNull()
        ->and($evaluator->qualifyingRun(complianceSamplesAtOffsets([15, 10, 5, 0]), 33.5138, 36.2765))->toBeNull();
});

it('breaks a dwell run on poor accuracy or a fix outside the conservative radius', function (): void {
    $evaluator = new WorkerComplianceVisitEvaluator();
    $poorAccuracySamples = complianceSamplesAtOffsets([16, 12, 8, 4, 0]);
    $poorAccuracySamples[2]['accuracy_m'] = 51;
    $outsideSamples = complianceSamplesAtOffsets([16, 12, 8, 4, 0]);
    $outsideSamples[2]['latitude'] += 0.001;

    expect($evaluator->qualifyingRun($poorAccuracySamples, 33.5138, 36.2765))->toBeNull()
        ->and($evaluator->qualifyingRun($outsideSamples, 33.5138, 36.2765))->toBeNull();
});

it('rejects a qualifying-radius path that shows sustained movement through the area', function (): void {
    $evaluator = new WorkerComplianceVisitEvaluator();
    $samples = [];
    for ($index = 0; $index <= 10; $index++) {
        $samples[] = [
            'captured_at' => CarbonImmutable::now()->subMinutes(40 - ($index * 4)),
            'latitude' => 33.5138 + (($index % 2 === 0 ? 1 : -1) * 0.0003),
            'longitude' => 36.2765,
            'accuracy_m' => 10,
        ];
    }

    expect($evaluator->qualifyingRun($samples, 33.5138, 36.2765))->toBeNull();
});

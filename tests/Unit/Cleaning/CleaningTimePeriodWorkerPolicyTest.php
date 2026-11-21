<?php

declare(strict_types=1);

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\User\Services\CleaningTimePeriodWorkerPolicy;

it('ignores personal working hours while retaining booking collision checks', function (): void {
    $worker = new Worker([
        'default_working_hours' => [
            'monday' => ['available' => false, 'data' => []],
        ],
    ]);
    $worker->setAttribute('id', 17);
    $at = CarbonImmutable::parse('2026-11-23 10:00:00');
    $policy = new CleaningTimePeriodWorkerPolicy();
    $workers = new Collection([$worker]);

    expect($policy->availableWorkersAt($workers, $at, []))->toBe(1)
        ->and($policy->availableWorkersAt($workers, $at, [17 => [[$at->timestamp, $at->addHour()->timestamp]]]))->toBe(0);
});

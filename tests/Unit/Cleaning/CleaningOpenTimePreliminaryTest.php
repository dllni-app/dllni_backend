<?php

declare(strict_types=1);

use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Services\CleaningOpenTimeBillingService;

it('calculates the preliminary open-time amount from worker count minimum duration and rounding', function (): void {
    $policy = CleaningBillingPolicy::query()->create([
        'name' => 'Agent B preliminary policy',
        'billing_mode' => CleaningBillingMode::ActualWorkingTime,
        'rules' => [
            'min_billable_minutes' => 60,
            'rounding_minutes' => 30,
            'hard_max_minutes' => 600,
            'warning_minutes' => 45,
            'extension_options' => [15, 45, 90],
        ],
        'is_active' => true,
        'is_default' => false,
    ]);

    $pricing = app(CleaningOpenTimeBillingService::class)->preliminary(
        hourlyRate: 100,
        workerCount: 2,
        policy: $policy,
        expectedMaxMinutes: 240,
    );

    expect($pricing['hourlyRate'])->toBe(100.0)
        ->and($pricing['requestedWorkerCount'])->toBe(2)
        ->and($pricing['minimumBillableMinutes'])->toBe(60)
        ->and($pricing['roundingMinutes'])->toBe(30)
        ->and($pricing['expectedMaxMinutes'])->toBe(240)
        ->and($pricing['hardMaxMinutes'])->toBe(600)
        ->and($pricing['warningMinutes'])->toBe(45)
        ->and($pricing['extensionOptions'])->toBe([15, 45, 90])
        ->and($pricing['preliminaryBillableMinutes'])->toBe(60)
        ->and($pricing['preliminaryAmount'])->toBe(200.0)
        ->and($pricing['maximumEstimatedAmount'])->toBe(800.0);
});

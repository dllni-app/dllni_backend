<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Cleaning\Services\CleaningSearchDiscoveryWindowPolicy;

test('six-hour escalation does not expire a future scheduled service', function (): void {
    $clock = CarbonImmutable::parse('2026-11-21 10:00:00', 'Asia/Damascus');
    $searchStartedAt = $clock->subHours(7);
    expect($searchStartedAt->lessThan($clock->subHours(6)))->toBeTrue()
        ->and((new CleaningSearchDiscoveryWindowPolicy)->isFutureLocalStart('2026-11-23', '10:00:00', $clock))->toBeTrue();
});

test('past-due or current service start is never rediscovered', function (): void {
    $clock = CarbonImmutable::parse('2026-11-21 15:00:00', 'Asia/Damascus');
    $policy = new CleaningSearchDiscoveryWindowPolicy;
    expect($policy->isFutureLocalStart('2026-11-21', '14:00:00', $clock))->toBeFalse()
        ->and($policy->isFutureLocalStart('2026-11-21', '15:00:00', $clock))->toBeFalse()
        ->and($policy->isFutureLocalStart('2026-11-21', '15:01:00', $clock))->toBeTrue()
        ->and($policy->isFutureLocalStart('2026-11-22', '08:00', $clock))->toBeTrue();
});

test('invalid dates, missing start and invalid clock time fail closed', function (): void {
    $clock = CarbonImmutable::parse('2026-11-21 15:00:00', 'Asia/Damascus');
    $policy = new CleaningSearchDiscoveryWindowPolicy;
    expect($policy->isFutureLocalStart(null, '12:00', $clock))->toBeFalse()
        ->and($policy->isFutureLocalStart('2026-11-21', '', $clock))->toBeFalse()
        ->and($policy->isFutureLocalStart('2026-02-30', '17:00:00', $clock))->toBeFalse()
        ->and($policy->isFutureLocalStart('2026-11-23', '24:01', $clock))->toBeFalse();
});

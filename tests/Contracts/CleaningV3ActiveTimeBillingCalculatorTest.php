<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3ActiveTimeBillingCalculator;

test('event assistance rounds once per visit with a 60 minute minimum and 30 minute step', function (): void {
    $calculator = new CleaningV3ActiveTimeBillingCalculator;

    foreach ([
        30 * 60 => [30, 60],
        60 * 60 => [60, 60],
        61 * 60 => [61, 90],
        90 * 60 => [90, 90],
        (90 * 60) + 1 => [91, 120],
    ] as $seconds => [$actual, $billed]) {
        $result = $calculator->calculate($seconds, 60, 30);
        expect($result['activeMinutesRoundedUp'])->toBe($actual)
            ->and($result['billableMinutes'])->toBe($billed)
            ->and($result['canCharge'])->toBeFalse()
            ->and($result['canSettle'])->toBeFalse();
    }
});

test('approved pauses do not bill paused seconds or restart the minimum for each interval', function (): void {
    // 31 min work + 20 min approved pause + 31 min work.
    // Two independently rounded intervals would incorrectly charge 120 min.
    $activeSeconds = (31 + 31) * 60;
    $result = (new CleaningV3ActiveTimeBillingCalculator)->calculate($activeSeconds, 60, 30);

    expect($result['activeSeconds'])->toBe(3720)
        ->and($result['billableMinutes'])->toBe(90)
        ->and($result['activeMinutesRoundedUp'])->toBe(62);
});

test('zero active work means zero time charge regardless of minimum hours', function (): void {
    $result = (new CleaningV3ActiveTimeBillingCalculator)->calculate(0, 120, 15);

    expect($result['activeSeconds'])->toBe(0)
        ->and($result['billableMinutes'])->toBe(0)
        ->and($result['canSettle'])->toBeFalse();
});

test('hourly minimum and step stay bound to the server approved rules', function (): void {
    $calculator = new CleaningV3ActiveTimeBillingCalculator;

    expect($calculator->calculate(121 * 60, 120, 15)['billableMinutes'])->toBe(135)
        ->and($calculator->calculate(120 * 60, 120, 15)['billableMinutes'])->toBe(120)
        ->and($calculator->calculate(1, 120, 15)['billableMinutes'])->toBe(120)
        ->and($calculator->calculate(480 * 60, 60, 30)['billableMinutes'])->toBe(480);
});

test('a rounded result exceeding the visit ceiling fails instead of silently truncating', function (): void {
    $calculator = new CleaningV3ActiveTimeBillingCalculator;

    expect(fn () => $calculator->calculate(91 * 60, 60, 30, 90))
        ->toThrow(DomainException::class, 'V3_TIME_BILLING_LIMIT_EXCEEDED');
    expect(fn () => $calculator->calculate(480 * 60, 100, 60, 480))
        ->toThrow(DomainException::class, 'V3_TIME_BILLING_LIMIT_EXCEEDED');
});

test('invalid time rules and impossible active intervals are never authorized', function (): void {
    $calculator = new CleaningV3ActiveTimeBillingCalculator;

    foreach ([
        [-1, 60, 30, 480],
        [28801, 60, 30, 480],
        [100, 0, 30, 480],
        [100, 481, 30, 480],
        [100, 60, 0, 480],
        [100, 60, 61, 480],
        [100, 120, 15, 100],
    ] as [$seconds, $minimum, $step, $ceiling]) {
        expect(fn () => $calculator->calculate($seconds, $minimum, $step, $ceiling))
            ->toThrow(DomainException::class, 'INVALID_V3_TIME_BILLING_RULE');
    }
});

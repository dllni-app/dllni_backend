<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\User\Services\OpenTimeScheduleService;

it('normalizes open-time sessions into canonical chronological order', function (): void {
    $plan = app(OpenTimeScheduleService::class)->resolve([
        'openTime' => [
            'expectedMaxMinutes' => 240,
            'sessions' => [
                ['date' => '2030-06-03', 'time' => '18:00', 'expectedMaxMinutes' => 180],
                ['date' => '2030-06-01', 'time' => '09:30'],
            ],
        ],
    ]);

    expect($plan)->not->toBeNull()
        ->and($plan['mode'])->toBe('multi_day')
        ->and($plan['sessionsCount'])->toBe(2)
        ->and($plan['totalExpectedMinutes'])->toBe(420)
        ->and($plan['sessions'])->toBe([
            [
                'sequence' => 1,
                'date' => '2030-06-01',
                'time' => '09:30',
                'expectedMaxMinutes' => 240,
            ],
            [
                'sequence' => 2,
                'date' => '2030-06-03',
                'time' => '18:00',
                'expectedMaxMinutes' => 180,
            ],
        ]);
});

it('aggregates independent open-time session quotes without changing policy snapshots', function (): void {
    $service = app(OpenTimeScheduleService::class);
    $plan = $service->resolve([
        'openTime' => [
            'sessions' => [
                ['date' => '2030-06-01', 'time' => '09:30', 'expectedMaxMinutes' => 120],
                ['date' => '2030-06-02', 'time' => '09:30', 'expectedMaxMinutes' => 180],
            ],
        ],
    ]);

    $quote = $service->quote($plan, [
        'basePrice' => 200,
        'travelFee' => 30,
        'adminMargin' => 20,
        'totalPrice' => 250,
        'currency' => 'SYP',
        'openTime' => [
            'hourlyRate' => 100,
            'requestedWorkerCount' => 2,
            'minimumBillableMinutes' => 60,
            'roundingMinutes' => 15,
            'hardMaxMinutes' => 480,
            'warningMinutes' => 30,
            'extensionOptions' => [15, 30, 60],
        ],
    ]);

    expect($quote['basePrice'])->toBe(400.0)
        ->and($quote['travelFee'])->toBe(60.0)
        ->and($quote['adminMargin'])->toBe(40.0)
        ->and($quote['totalPrice'])->toBe(500.0)
        ->and($quote['schedule']['isOpenTime'])->toBeTrue()
        ->and($quote['schedule']['sessionsCount'])->toBe(2)
        ->and($quote['openTime']['totalExpectedMinutes'])->toBe(300)
        ->and($quote['openTime']['sessions'][0]['maximumEstimatedAmount'])->toBe(400.0)
        ->and($quote['openTime']['sessions'][1]['maximumEstimatedAmount'])->toBe(600.0);
});

it('rejects duplicate open-time session slots', function (): void {
    expect(fn () => app(OpenTimeScheduleService::class)->resolve([
        'openTime' => [
            'sessions' => [
                ['date' => '2030-06-01', 'time' => '09:30'],
                ['date' => '2030-06-01', 'time' => '09:30'],
            ],
        ],
    ]))->toThrow(ValidationException::class);
});

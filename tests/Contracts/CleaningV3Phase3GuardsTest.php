<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3PostedAllocationConservation;
use Modules\Cleaning\Services\CleaningV3VisitPlanException;
use Modules\Cleaning\Services\CleaningV3VisitPlanner;
use Modules\Cleaning\Services\CleaningV3VisitStatusProjector;

function v3TestInput(): array
{
    return [
        'contractVersion' => 3,
        'catalogVersionKey' => 'approved-catalog-key',
        'catalogRevision' => str_repeat('a', 64),
        'appointments' => [
            [
                'appointmentKey' => 'apt-two',
                'visits' => [[
                    'occurrenceKey' => 'occ-two', 'scheduledDate' => '2026-12-07',
                    'scheduledTime' => '13:00', 'durationMinutes' => 120, 'requiredWorkers' => 3,
                ]],
            ],
            [
                'appointmentKey' => 'apt-one',
                'visits' => [
                    [
                        'occurrenceKey' => 'occ-one-b', 'scheduledDate' => '2026-11-28',
                        'scheduledTime' => '14:00', 'durationMinutes' => 60, 'requiredWorkers' => 1,
                    ],
                    [
                        'occurrenceKey' => 'occ-one-a', 'scheduledDate' => '2026-11-28',
                        'scheduledTime' => '10:00', 'durationMinutes' => 90, 'requiredWorkers' => 2,
                    ],
                ],
            ],
        ],
    ];
}

function v3TestNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-21T12:00:00+03:00');
}

test('V3 visit planner sorts groups and same-day splits without creating charges or visit IDs', function (): void {
    $result = (new CleaningV3VisitPlanner)->plan(v3TestInput(), v3TestNow());
    expect($result['previewOnly'])->toBeTrue()
        ->and($result['canQuote'])->toBeFalse()
        ->and($result['canCreateBooking'])->toBeFalse()
        ->and($result['summary']['visitCount'])->toBe(3)
        ->and($result['summary']['totalActiveMinutes'])->toBe(270)
        ->and($result['summary']['peakRequestedWorkers'])->toBe(3)
        ->and($result['summary']['price'])->toBeNull()
        ->and($result['appointments'][0]['appointmentKey'])->toBe('apt-one')
        ->and($result['appointments'][0]['visits'][0]['occurrenceKey'])->toBe('occ-one-a')
        ->and($result['appointments'][0]['visits'][0]['visitId'])->toBeNull()
        ->and($result['requiresClearedFundingAdmission'])->toBeTrue();
});

test('V3 draft fingerprint stays stable when client sends groups in another order', function (): void {
    $planner = new CleaningV3VisitPlanner;
    $input = v3TestInput();
    $hash = $planner->plan($input, v3TestNow())['draftFingerprint'];
    $input['appointments'] = array_reverse($input['appointments']);
    $input['appointments'][0]['visits'] = array_reverse($input['appointments'][0]['visits']);
    expect($planner->plan($input, v3TestNow())['draftFingerprint'])->toBe($hash);
    $input['appointments'][0]['visits'][0]['durationMinutes'] = 91;
    expect($planner->plan($input, v3TestNow())['draftFingerprint'])->not->toBe($hash);
});

test('V3 planner rejects duplicate visit identity and appointment identity', function (): void {
    $planner = new CleaningV3VisitPlanner;
    $input = v3TestInput();
    $input['appointments'][1]['visits'][0]['occurrenceKey'] = 'occ-two';
    expect(fn () => $planner->plan($input, v3TestNow()))
        ->toThrow(CleaningV3VisitPlanException::class, 'DUPLICATE_OCCURRENCE_KEY');
    $input = v3TestInput();
    $input['appointments'][1]['appointmentKey'] = 'apt-two';
    expect(fn () => $planner->plan($input, v3TestNow()))
        ->toThrow(CleaningV3VisitPlanException::class, 'DUPLICATE_APPOINTMENT_KEY');
});

test('V3 refuses past, cross-midnight, 510-minute and excessive-crew visits', function (): void {
    $planner = new CleaningV3VisitPlanner;
    foreach ([
        ['scheduledDate', '2026-11-01', 'VISIT_MUST_BE_FUTURE'],
        ['scheduledTime', '23:30', 'CROSS_MIDNIGHT_NOT_SUPPORTED'],
        ['durationMinutes', 510, 'INVALID_ACTIVE_DURATION'],
        ['requiredWorkers', 21, 'INVALID_CREW_CAPACITY'],
    ] as [$field, $value, $code]) {
        $input = v3TestInput();
        $input['appointments'][0]['visits'][0][$field] = $value;
        expect(fn () => $planner->plan($input, v3TestNow()))
            ->toThrow(CleaningV3VisitPlanException::class, $code);
    }
});

test('V3 refuses client financial fields and flags same-day overlap pending crew checking', function (): void {
    $planner = new CleaningV3VisitPlanner;
    $input = v3TestInput();
    $input['pricing'] = ['totalPrice' => 10];
    expect(fn () => $planner->plan($input, v3TestNow()))
        ->toThrow(CleaningV3VisitPlanException::class, 'CLIENT_FINANCIAL_INPUT_FORBIDDEN');
    $input = v3TestInput();
    $input['appointments'][0]['visits'][0]['fundingSourceId'] = 77;
    expect(fn () => $planner->plan($input, v3TestNow()))
        ->toThrow(CleaningV3VisitPlanException::class, 'CLIENT_FINANCIAL_INPUT_FORBIDDEN');
    $input = v3TestInput();
    $input['appointments'][1]['visits'][0]['scheduledTime'] = '10:30';
    $result = $planner->plan($input, v3TestNow());
    expect($result['appointments'][0]['sameDayOverlapRequiresCrewResolution'])->toBeTrue();
});

test('V3 parent state is partial only when all current siblings terminal', function (): void {
    $projector = new CleaningV3VisitStatusProjector;
    $partial = $projector->project(['completed', 'cancelled', 'skipped', 'superseded']);
    expect($partial['status'])->toBe('partially_completed')
        ->and($partial['legacyStatus'])->toBe('completed')
        ->and($partial['superseded'])->toBe(1)
        ->and($partial['total'])->toBe(3);
    $open = $projector->project(['completed', 'skipped', 'scheduled']);
    expect($open['status'])->toBe('pending')->and($open['open'])->toBe(1);
    expect($projector->project(['completed', 'paused'])['status'])->toBe('in_progress')
        ->and($projector->project(['cancelled', 'skipped'])['status'])->toBe('cancelled')
        ->and($projector->project(['completed', 'completed'])['status'])->toBe('completed');
    expect(fn () => $projector->project(['superseded']))->toThrow(InvalidArgumentException::class);
});

function v3PostedMovements(): array
{
    return [
        ['reference' => 'collect-1', 'kind' => 'collection', 'status' => 'posted', 'currency' => 'SYP', 'amount' => '100.00'],
        ['reference' => 'refund-1', 'kind' => 'refund', 'status' => 'posted', 'currency' => 'SYP', 'amount' => '40.00'],
    ];
}

function v3Allocations(): array
{
    return [
        ['reference' => 'collect-1', 'visitId' => 10, 'kind' => 'collection', 'currency' => 'SYP', 'amount' => '60.00'],
        ['reference' => 'collect-1', 'visitId' => 11, 'kind' => 'collection', 'currency' => 'SYP', 'amount' => '40.00'],
        ['reference' => 'refund-1', 'visitId' => 11, 'kind' => 'refund', 'currency' => 'SYP', 'amount' => '40.00'],
    ];
}

test('V3 posted allocations conserve both collection and refund per reference and visit', function (): void {
    $report = (new CleaningV3PostedAllocationConservation)->verify(v3PostedMovements(), v3Allocations());
    expect($report['status'])->toBe('conserved')
        ->and($report['totalCollected'])->toBe('100.00')
        ->and($report['totalRefunded'])->toBe('40.00')
        ->and($report['netCollected'])->toBe('60.00')
        ->and($report['visits'][10]['netCollected'])->toBe('60.00')
        ->and($report['visits'][11]['netCollected'])->toBe('0.00');
});

test('V3 conservation refuses mismatched posted amount, pending and orphan allocation', function (): void {
    $guard = new CleaningV3PostedAllocationConservation;
    $allocations = v3Allocations();
    $allocations[1]['amount'] = '39.99';
    expect(fn () => $guard->verify(v3PostedMovements(), $allocations))
        ->toThrow(DomainException::class, 'POSTED_MOVEMENT_ALLOCATION_MISMATCH');

    $posted = v3PostedMovements();
    $posted[0]['status'] = 'pending';
    expect(fn () => $guard->verify($posted, v3Allocations()))
        ->toThrow(DomainException::class, 'INVALID_OR_DUPLICATE_POSTED_MOVEMENT');

    $allocations = v3Allocations();
    $allocations[0]['reference'] = 'missing';
    expect(fn () => $guard->verify(v3PostedMovements(), $allocations))
        ->toThrow(DomainException::class, 'UNPOSTED_OR_MISMATCHED_VISIT_ALLOCATION');
});

test('V3 conservation rejects duplicate posting, duplicated allocation and SYP precision', function (): void {
    $guard = new CleaningV3PostedAllocationConservation;
    $posted = v3PostedMovements();
    $posted[] = $posted[0];
    expect(fn () => $guard->verify($posted, v3Allocations()))
        ->toThrow(DomainException::class, 'INVALID_OR_DUPLICATE_POSTED_MOVEMENT');
    $allocations = v3Allocations();
    $allocations[] = $allocations[0];
    expect(fn () => $guard->verify(v3PostedMovements(), $allocations))
        ->toThrow(DomainException::class, 'DUPLICATE_VISIT_ALLOCATION');
    $posted = v3PostedMovements();
    $posted[0]['amount'] = '100.001';
    expect(fn () => $guard->verify($posted, v3Allocations()))
        ->toThrow(DomainException::class, 'INVALID_SYP_DECIMAL');
});

test('V3 conservation forbids refund greater than collection on a visit', function (): void {
    $posted = v3PostedMovements();
    $posted[1]['amount'] = '100.01';
    $allocations = v3Allocations();
    $allocations[2]['amount'] = '100.01';
    expect(fn () => (new CleaningV3PostedAllocationConservation)->verify($posted, $allocations))
        ->toThrow(DomainException::class, 'REFUND_EXCEEDS_POSTED_VISIT_COLLECTION');
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Cleaning\Services\CleaningV3QuoteSnapshotAssembler;
use Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope;
use Modules\Cleaning\Services\CleaningV3VisitPlanner;

function v3ApprovedAssemblyFixture(): array
{
    return app(CleaningV3VisitPlanner::class)->plan([
        'contractVersion' => 3,
        'catalogVersionKey' => 'catalog-1',
        'catalogRevision' => str_repeat('a', 64),
        'appointments' => [[
            'appointmentKey' => 'event-12h',
            'visits' => [
                [
                    'occurrenceKey' => 'event-8h',
                    'scheduledDate' => '2026-12-15',
                    'scheduledTime' => '09:00',
                    'durationMinutes' => 480,
                    'requiredWorkers' => 2,
                ],
                [
                    'occurrenceKey' => 'event-4h',
                    'scheduledDate' => '2026-12-15',
                    'scheduledTime' => '17:00',
                    'durationMinutes' => 240,
                    'requiredWorkers' => 2,
                ],
            ],
        ]],
    ], new DateTimeImmutable('2026-11-23 09:00:00', new DateTimeZone('Asia/Damascus')));
}

function v3ApprovedAssemblyPrices(): array
{
    return [
        ['visitKey' => 'event-8h', 'eligibleNetBeforeDiscount' => '650000.00'],
        ['visitKey' => 'event-4h', 'eligibleNetBeforeDiscount' => '350000.00'],
    ];
}

test('the approved plan combines cents-exact discount, crew fees and immutable visit identities', function (): void {
    $assembler = app(CleaningV3QuoteSnapshotAssembler::class);
    $snapshot = $assembler->assemble(
        v3ApprovedAssemblyFixture(),
        v3ApprovedAssemblyPrices(),
        2,
        ['type' => 'fixed', 'amount' => '100000.00'],
    );

    expect($snapshot['contractVersion'])->toBe(3)
        ->and($snapshot['discountTotal'])->toBe('100000.00')
        ->and($snapshot['eligibleBaseTotal'])->toBe('1000000.00')
        ->and($snapshot['additionalWorkerFees'])->toBe('300000.00')
        ->and($snapshot['totalWithAdditionalWorkerFees'])->toBe('1200000.00')
        ->and(array_column($snapshot['visits'], 'visitKey'))->toBe(['event-8h', 'event-4h'])
        ->and(array_column($snapshot['visits'], 'discountAllocated'))->toBe(['65000.00', '35000.00'])
        ->and(array_column($snapshot['visits'], 'additionalWorkerFee'))->toBe(['150000.00', '150000.00'])
        ->and($snapshot['visits'][0]['appointmentKey'])->toBe('event-12h')
        ->and($snapshot['visits'][0]['priceRevision'])->toBe(2);

    $at = CarbonImmutable::parse('2026-11-23T10:00:00Z');
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    $signed = $signer->seal(17, $snapshot, 15, $at);
    expect($signer->verify($signed, 17, 'catalog-1', str_repeat('a', 64), $at))
        ->toEqual($snapshot);
});

test('line input order cannot move discounted amounts between visits', function (): void {
    $assembler = app(CleaningV3QuoteSnapshotAssembler::class);
    $base = $assembler->assemble(v3ApprovedAssemblyFixture(), v3ApprovedAssemblyPrices(), 1);
    $swapped = $assembler->assemble(v3ApprovedAssemblyFixture(), array_reverse(v3ApprovedAssemblyPrices()), 1);

    expect($base)->toEqual($swapped)
        ->and($base['additionalWorkerFees'])->toBe('300000.00')
        ->and($base['discountTotal'])->toBe('0.00');
});

test('missing, repeated or unauthorized visit prices fail without a quote snapshot', function (): void {
    $assembler = app(CleaningV3QuoteSnapshotAssembler::class);
    $plan = v3ApprovedAssemblyFixture();

    foreach ([
        [v3ApprovedAssemblyPrices()[0]],
        [
            v3ApprovedAssemblyPrices()[0],
            v3ApprovedAssemblyPrices()[0],
        ],
        [
            v3ApprovedAssemblyPrices()[0],
            ['visitKey' => 'other', 'eligibleNetBeforeDiscount' => '350000.00'],
        ],
        [
            v3ApprovedAssemblyPrices()[0],
            [...v3ApprovedAssemblyPrices()[1], 'pricedWorkers' => 1],
        ],
    ] as $bad) {
        expect(fn () => $assembler->assemble($plan, $bad, 1))
            ->toThrow(DomainException::class, 'V3_PRICING_VISIT_COVERAGE_MISMATCH');
    }
});

test('quote snapshot worker count is always taken from the server visit plan', function (): void {
    $assembler = app(CleaningV3QuoteSnapshotAssembler::class);
    $plan = v3ApprovedAssemblyFixture();
    $snapshot = $assembler->assemble($plan, v3ApprovedAssemblyPrices(), 1);
    expect(array_column($snapshot['visits'], 'pricedWorkers'))->toBe([2, 2]);

    $plan['appointments'][0]['visits'][1]['requiredWorkers'] = 0;
    expect(fn () => $assembler->assemble($plan, v3ApprovedAssemblyPrices(), 1))
        ->toThrow(DomainException::class, 'V3_PLAN_FINGERPRINT_MISMATCH');
});

test('unapproved, malformed or incoherent visit plans never become financial snapshots', function (): void {
    $assembler = app(CleaningV3QuoteSnapshotAssembler::class);
    $plan = v3ApprovedAssemblyFixture();

    $bad = $plan;
    $bad['contractVersion'] = 2;
    expect(fn () => $assembler->assemble($bad, v3ApprovedAssemblyPrices(), 1))
        ->toThrow(DomainException::class, 'INVALID_V3_VERIFIED_PLAN');

    $bad = $plan;
    $bad['appointments'][0]['visits'][1]['occurrenceKey'] = 'event-8h';
    expect(fn () => $assembler->assemble($bad, v3ApprovedAssemblyPrices(), 1))
        ->toThrow(DomainException::class, 'V3_PLAN_FINGERPRINT_MISMATCH');

    expect(fn () => $assembler->assemble($plan, v3ApprovedAssemblyPrices(), 0))
        ->toThrow(DomainException::class, 'INVALID_V3_VERIFIED_PLAN');

    $bad = $plan;
    $bad['catalogRevision'] = 'changed';
    expect(fn () => $assembler->assemble($bad, v3ApprovedAssemblyPrices(), 1))
        ->toThrow(DomainException::class, 'INVALID_V3_VERIFIED_PLAN');
});

test('assembly does not open V3 public quote or booking creation routes', function (): void {
    Laravel\Sanctum\Sanctum::actingAs(App\Models\User::factory()->create(['is_active' => true]));
    $this->postJson('/api/v1/cleaning/v3/quotes', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
    $this->postJson('/api/v1/cleaning/v3/bookings', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
});

test('repricing cannot silently change visit crew or duration while reusing an older plan fingerprint', function (): void {
    $assembler = app(CleaningV3QuoteSnapshotAssembler::class);
    $pricings = v3ApprovedAssemblyPrices();

    $workersTampered = v3ApprovedAssemblyFixture();
    $workersTampered['appointments'][0]['visits'][1]['requiredWorkers'] = 3;
    expect(fn () => $assembler->assemble($workersTampered, $pricings, 1))
        ->toThrow(DomainException::class, 'V3_PLAN_FINGERPRINT_MISMATCH');

    $durationTampered = v3ApprovedAssemblyFixture();
    $durationTampered['appointments'][0]['visits'][0]['durationMinutes'] = 300;
    expect(fn () => $assembler->assemble($durationTampered, $pricings, 1))
        ->toThrow(DomainException::class, 'V3_PLAN_FINGERPRINT_MISMATCH');

    $publishedDigestTampered = v3ApprovedAssemblyFixture();
    $publishedDigestTampered['catalogRevision'] = str_repeat('c', 64);
    expect(fn () => $assembler->assemble($publishedDigestTampered, $pricings, 1))
        ->toThrow(DomainException::class, 'V3_PLAN_FINGERPRINT_MISMATCH');
});

<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope;
use Modules\Cleaning\Services\CleaningV3VisitDiscountAllocator;

function v3SignedSnapshot(): array
{
    $allocated = app(CleaningV3VisitDiscountAllocator::class)->allocate([
        ['visitKey' => 'event-8h', 'pricedWorkers' => 2, 'eligibleNetBeforeDiscount' => '650000.00'],
        ['visitKey' => 'event-4h', 'pricedWorkers' => 2, 'eligibleNetBeforeDiscount' => '350000.00'],
    ], ['type' => 'fixed', 'amount' => '100000.00']);

    return [
        'contractVersion' => 3,
        'catalogVersionKey' => 'catalog-1',
        'catalogRevision' => str_repeat('a', 64),
        'draftFingerprint' => str_repeat('b', 64),
        'pricingRevision' => 1,
        'currency' => 'SYP',
        'visits' => array_map(static fn (array $row): array => [
            'visitKey' => $row['visitKey'],
            'appointmentKey' => 'event-12h',
            'occurrenceKey' => $row['visitKey'],
            'priceRevision' => 1,
            'pricedWorkers' => $row['pricedWorkers'],
            'eligibleNetBeforeDiscount' => $row['eligibleNetBeforeDiscount'],
            'discountAllocated' => $row['discountAllocated'],
            'netBeforeAdditionalWorkerFee' => $row['netBeforeAdditionalWorkerFee'],
            'additionalWorkerFee' => $row['additionalWorkerFee'],
            'totalWithAdditionalWorkerFee' => $row['totalWithAdditionalWorkerFee'],
        ], $allocated['visits']),
        'eligibleBaseTotal' => $allocated['eligibleBaseTotal'],
        'discountTotal' => $allocated['discountTotal'],
        'netBeforeAdditionalWorkerFees' => $allocated['netBeforeAdditionalWorkerFees'],
        'additionalWorkerFees' => $allocated['additionalWorkerFees'],
        'totalWithAdditionalWorkerFees' => $allocated['totalWithAdditionalWorkerFees'],
    ];
}

test('an immutable V3 quote HMAC binds actor, catalog revision, visits and exact financial totals', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    $snapshot = v3SignedSnapshot();
    $at = CarbonImmutable::parse('2026-11-23T12:00:00+00:00');
    $signed = $signer->seal(17, $snapshot, 15, $at);

    expect($signed['customerId'])->toBe(17)
        ->and($signed['signature'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($signed['quoteId'])->toBeUuid()
        ->and($signed['expiresAt'])->toBe('2026-11-23T12:15:00+00:00')
        ->and($signer->verify($signed, 17, 'catalog-1', str_repeat('a', 64), $at))->toEqual($snapshot);
});

test('a signed quote rejects another user or another approved published catalog revision', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    $now = CarbonImmutable::parse('2026-11-23T12:00:00+00:00');
    $signed = $signer->seal(17, v3SignedSnapshot(), 15, $now);

    expect(fn () => $signer->verify($signed, 18, 'catalog-1', str_repeat('a', 64), $now))
        ->toThrow(DomainException::class, 'INVALID_V3_QUOTE_ENVELOPE');
    expect(fn () => $signer->verify($signed, 17, 'catalog-2', str_repeat('a', 64), $now))
        ->toThrow(DomainException::class, 'STALE_V3_CATALOG_QUOTE');
    expect(fn () => $signer->verify($signed, 17, 'catalog-1', str_repeat('c', 64), $now))
        ->toThrow(DomainException::class, 'STALE_V3_CATALOG_QUOTE');
});

test('expired quotes and manipulated expiry dates are unusable', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    $now = CarbonImmutable::parse('2026-11-23T12:00:00+00:00');
    $signed = $signer->seal(17, v3SignedSnapshot(), 15, $now);

    expect(fn () => $signer->verify($signed, 17, 'catalog-1', str_repeat('a', 64), $now->addMinutes(15)))
        ->toThrow(DomainException::class, 'V3_QUOTE_EXPIRED');
    expect(fn () => $signer->verify([...$signed, 'expiresAt' => '2026-11-23T12:30:00+00:00'], 17, 'catalog-1', str_repeat('a', 64), $now))
        ->toThrow(DomainException::class, 'V3_QUOTE_SIGNATURE_MISMATCH');
});

test('changing one signed worker surcharge or visit total invalidates the envelope', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    $now = CarbonImmutable::parse('2026-11-23T12:00:00+00:00');
    $signed = $signer->seal(17, v3SignedSnapshot(), 15, $now);
    $tampered = $signed;
    $tampered['snapshot']['visits'][0]['eligibleNetBeforeDiscount'] = '700000.00';

    expect(fn () => $signer->verify($tampered, 17, 'catalog-1', str_repeat('a', 64), $now))
        ->toThrow(DomainException::class, 'INVALID_V3_QUOTE_VISIT_TOTALS');

    $tampered = $signed;
    $tampered['snapshot']['visits'][0]['pricedWorkers'] = 3;
    $tampered['snapshot']['visits'][0]['additionalWorkerFee'] = '300000.00';
    $tampered['snapshot']['visits'][0]['totalWithAdditionalWorkerFee'] = '885000.00';
    $tampered['snapshot']['additionalWorkerFees'] = '450000.00';
    $tampered['snapshot']['totalWithAdditionalWorkerFees'] = '1350000.00';

    expect(fn () => $signer->verify($tampered, 17, 'catalog-1', str_repeat('a', 64), $now))
        ->toThrow(DomainException::class, 'V3_QUOTE_SIGNATURE_MISMATCH');
});

test('unknown client monetary fields, duplicate visit identities and off-snapshot totals fail closed', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    $snapshot = v3SignedSnapshot();

    $extra = $snapshot;
    $extra['clientProvidedDiscount'] = '50000.00';
    expect(fn () => $signer->seal(17, $extra))
        ->toThrow(DomainException::class, 'INVALID_V3_QUOTE_SNAPSHOT');

    $duplicate = $snapshot;
    $duplicate['visits'][1]['occurrenceKey'] = $duplicate['visits'][0]['occurrenceKey'];
    expect(fn () => $signer->seal(17, $duplicate))
        ->toThrow(DomainException::class, 'INVALID_V3_QUOTE_VISIT');

    $wrong = $snapshot;
    $wrong['totalWithAdditionalWorkerFees'] = '999999.00';
    expect(fn () => $signer->seal(17, $wrong))
        ->toThrow(DomainException::class, 'INVALID_V3_QUOTE_TOTALS');
});

test('financial amounts cannot be floats, negative, scientific notation or over-precision', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    foreach ([650000.0, '-1.00', '1e4', '1.999', 'NaN'] as $invalid) {
        $snapshot = v3SignedSnapshot();
        $snapshot['visits'][0]['eligibleNetBeforeDiscount'] = $invalid;
        expect(fn () => $signer->seal(17, $snapshot))
            ->toThrow(DomainException::class, 'INVALID_V3_QUOTE_MONEY');
    }
});

test('signing requires a usable server secret and never degrades to unsigned quotes', function (): void {
    $signer = app(CleaningV3SignedQuoteEnvelope::class);
    config()->set('app.key', '');

    expect(fn () => $signer->seal(17, v3SignedSnapshot()))
        ->toThrow(DomainException::class, 'V3_SIGNING_KEY_UNAVAILABLE');
});

test('V3 financial API still refuses booking creation and quote issuance regardless of codec availability', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson('/api/v1/cleaning/v3/quotes', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
    $this->postJson('/api/v1/cleaning/v3/bookings', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
});

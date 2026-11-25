<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3VisitDiscountAllocator;

function discountVisit(string $key, string $base, int $workers): array
{
    return [
        'visitKey' => $key,
        'pricedWorkers' => $workers,
        'eligibleNetBeforeDiscount' => $base,
    ];
}

test('fixed-value booking coupon is distributed before the independent extra-worker fee', function (): void {
    $result = app(CleaningV3VisitDiscountAllocator::class)->allocate([
        discountVisit('event-8h', '650000.00', 2),
        discountVisit('event-4h', '350000.00', 2),
    ], ['type' => 'fixed', 'amount' => '100000.00']);

    expect($result['eligibleBaseTotal'])->toBe('1000000.00')
        ->and($result['discountTotal'])->toBe('100000.00')
        ->and($result['netBeforeAdditionalWorkerFees'])->toBe('900000.00')
        ->and($result['additionalWorkerFees'])->toBe('300000.00')
        ->and($result['totalWithAdditionalWorkerFees'])->toBe('1200000.00')
        ->and(array_column($result['visits'], 'discountAllocated'))->toBe([
            '65000.00', '35000.00',
        ])
        ->and(array_column($result['visits'], 'additionalWorkerFee'))->toBe([
            '150000.00', '150000.00',
        ])
        ->and($result['canQuote'])->toBeFalse()
        ->and($result['canCharge'])->toBeFalse()
        ->and($result['canSettle'])->toBeFalse();
});

test('a percentage coupon changes eligible base but never changes the worker surcharge', function (): void {
    $allocator = app(CleaningV3VisitDiscountAllocator::class);
    $original = $allocator->allocate([
        discountVisit('one', '650000.00', 3),
    ]);
    $discounted = $allocator->allocate([
        discountVisit('one', '650000.00', 3),
    ], ['type' => 'percent', 'amount' => '10.00']);

    expect($original['additionalWorkerFees'])->toBe('300000.00')
        ->and($discounted['additionalWorkerFees'])->toBe('300000.00')
        ->and($discounted['discountTotal'])->toBe('65000.00')
        ->and($discounted['netBeforeAdditionalWorkerFees'])->toBe('585000.00')
        ->and($discounted['totalWithAdditionalWorkerFees'])->toBe('885000.00')
        ->and($discounted['visits'][0]['feeCommissionEligible'])->toBeFalse()
        ->and($discounted['visits'][0]['couponAppliesToWorkerFee'])->toBeFalse();
});

test('smallest-remainder tie is deterministic by visit identity rather than presentation order', function (): void {
    $allocator = app(CleaningV3VisitDiscountAllocator::class);
    $input = [
        discountVisit('c', '0.01', 1),
        discountVisit('a', '0.01', 1),
        discountVisit('b', '0.01', 1),
    ];

    $result = $allocator->allocate($input, ['type' => 'fixed', 'amount' => '0.01']);
    $reordered = $allocator->allocate(array_reverse($input), ['type' => 'fixed', 'amount' => '0.01']);

    $amounts = static fn (array $priced): array => collect($priced['visits'])
        ->mapWithKeys(static fn (array $row): array => [$row['visitKey'] => $row['discountAllocated']])
        ->sortKeys()->all();

    expect($amounts($result))->toBe(['a' => '0.01', 'b' => '0.00', 'c' => '0.00'])
        ->and($amounts($reordered))->toBe($amounts($result))
        ->and($result['totalWithAdditionalWorkerFees'])->toBe('0.02');
});

test('a too-large fixed coupon is capped to eligible net and cannot reduce the surcharge', function (): void {
    $result = app(CleaningV3VisitDiscountAllocator::class)->allocate([
        discountVisit('a', '100.00', 2),
        discountVisit('b', '0.00', 1),
    ], ['type' => 'fixed', 'amount' => '999999.00']);

    expect($result['discountTotal'])->toBe('100.00')
        ->and($result['additionalWorkerFees'])->toBe('150000.00')
        ->and($result['netBeforeAdditionalWorkerFees'])->toBe('0.00')
        ->and($result['totalWithAdditionalWorkerFees'])->toBe('150000.00');
});

test('the exact allocation is stable for large SYP amounts without integer overflow', function (): void {
    $allocator = app(CleaningV3VisitDiscountAllocator::class);
    $result = $allocator->allocate([
        discountVisit('large-a', '999999999999.99', 2),
        discountVisit('large-b', '999999999999.99', 1),
    ], ['type' => 'percent', 'amount' => '50.00']);

    expect($result['eligibleBaseTotal'])->toBe('1999999999999.98')
        ->and($result['discountTotal'])->toBe('999999999999.99')
        ->and($result['netBeforeAdditionalWorkerFees'])->toBe('999999999999.99')
        ->and($result['additionalWorkerFees'])->toBe('150000.00')
        ->and($result['totalWithAdditionalWorkerFees'])->toBe('1000000149999.99');
});

test('duplicate visit keys, unknown fields and noncanonical money inputs are rejected', function (): void {
    $allocator = app(CleaningV3VisitDiscountAllocator::class);

    expect(fn () => $allocator->allocate([]))
        ->toThrow(DomainException::class, 'INVALID_V3_VISIT_DISCOUNT_INPUT');
    expect(fn () => $allocator->allocate([
        discountVisit('repeated', '100.00', 1),
        discountVisit('repeated', '100.00', 2),
    ]))->toThrow(DomainException::class, 'INVALID_V3_VISIT_DISCOUNT_INPUT');

    $bad = discountVisit('a', '100.00', 1);
    $bad['couponAppliedToFee'] = true;
    expect(fn () => $allocator->allocate([$bad]))
        ->toThrow(DomainException::class, 'INVALID_V3_VISIT_DISCOUNT_INPUT');

    foreach (['-1', '1e6', '001', '1.999', 'NaN', '', '1000000000000'] as $badMoney) {
        expect(fn () => $allocator->allocate([discountVisit('a', $badMoney, 1)]))
            ->toThrow(DomainException::class, 'INVALID_V3_SYP_DECIMAL');
    }
    expect(fn () => $allocator->allocate([discountVisit('a', '100.00', 0)]))
        ->toThrow(DomainException::class, 'INVALID_V3_VISIT_DISCOUNT_INPUT');
});

test('percent above 100, coupon float amounts and unsupported types are rejected', function (): void {
    $allocator = app(CleaningV3VisitDiscountAllocator::class);
    $visit = [discountVisit('a', '100.00', 2)];

    expect(fn () => $allocator->allocate($visit, ['type' => 'percent', 'amount' => '100.01']))
        ->toThrow(DomainException::class, 'INVALID_V3_COUPON');
    expect(fn () => $allocator->allocate($visit, ['type' => 'fixed', 'amount' => 5.25]))
        ->toThrow(DomainException::class, 'INVALID_V3_SYP_DECIMAL');
    expect(fn () => $allocator->allocate($visit, ['type' => 'all', 'amount' => '100.00']))
        ->toThrow(DomainException::class, 'INVALID_V3_COUPON');
    expect(fn () => $allocator->allocate($visit, [
        'type' => 'fixed', 'amount' => '5.00', 'workerFeeIncluded' => true,
    ]))->toThrow(DomainException::class, 'INVALID_V3_COUPON');
});

test('company coupon is capped separately by each visit commission regardless of fee or eligible base', function (): void {
    $allocator = app(CleaningV3VisitDiscountAllocator::class);
    $visits = [discountVisit('a', '500000.00', 3), discountVisit('b', '100000.00', 1), discountVisit('c', '400000.00', 2)];
    $caps = ['a' => '1000.00', 'b' => '9000.00', 'c' => '0.00'];
    $result = $allocator->allocate($visits, ['type' => 'fixed', 'amount' => '100000.00'], $caps);
    expect($result['discountTotal'])->toBe('10000.00')
        ->and(array_column($result['visits'], 'discountAllocated'))->toBe(['1000.00', '9000.00', '0.00'])
        ->and($result['additionalWorkerFees'])->toBe('450000.00');
    $result = $allocator->allocate($visits, ['type' => 'percent', 'amount' => '0.50'], $caps);
    expect(array_column($result['visits'], 'discountAllocated'))->toBe(['500.00', '4500.00', '0.00']);
    expect(fn () => $allocator->allocate($visits, ['type' => 'fixed', 'amount' => '1.00'], ['a' => '1.00']))
        ->toThrow(DomainException::class, 'INVALID_V3_COMMISSION_CAPS');
});

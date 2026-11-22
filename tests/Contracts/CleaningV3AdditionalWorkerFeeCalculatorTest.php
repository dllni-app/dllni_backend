<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3AdditionalWorkerFeeCalculator;

function v3PricedVisit(string $key, int $workers, string $net = '650000.00'): array
{
    return [
        'visitKey' => $key,
        'pricedWorkers' => $workers,
        'netBeforeAdditionalWorkerFee' => $net,
    ];
}

test('one two and three priced workers incur exactly zero 150k and 300k SYP per visit', function (): void {
    $calculator = new CleaningV3AdditionalWorkerFeeCalculator;

    foreach ([
        1 => ['0.00', '650000.00'],
        2 => ['150000.00', '800000.00'],
        3 => ['300000.00', '950000.00'],
    ] as $workers => [$fee, $total]) {
        $result = $calculator->calculate([v3PricedVisit('visit-1', $workers)]);
        expect($result['additionalWorkerFees'])->toBe($fee)
            ->and($result['totalWithAdditionalWorkerFees'])->toBe($total)
            ->and($result['netBeforeAdditionalWorkerFees'])->toBe('650000.00')
            ->and($result['visits'][0]['additionalWorkerFee'])->toBe($fee)
            ->and($result['canCharge'])->toBeFalse()
            ->and($result['canSettle'])->toBeFalse();
    }
});

test('two separate event visits each have their own fee without an appointment-level charge', function (): void {
    $result = (new CleaningV3AdditionalWorkerFeeCalculator)->calculate([
        v3PricedVisit('event-hour-1-to-8', 2, '450000.00'),
        v3PricedVisit('event-hour-9-to-12', 2, '200000.00'),
    ]);
    expect($result['visitCount'])->toBe(2)
        ->and($result['netBeforeAdditionalWorkerFees'])->toBe('650000.00')
        ->and($result['additionalWorkerFees'])->toBe('300000.00')
        ->and($result['totalWithAdditionalWorkerFees'])->toBe('950000.00')
        ->and(array_column($result['visits'], 'additionalWorkerFee'))
        ->toBe(['150000.00', '150000.00']);
});

test('coupon-adjusted base changes but the independent additional-worker fee does not', function (): void {
    $calculator = new CleaningV3AdditionalWorkerFeeCalculator;

    $before = $calculator->calculate([v3PricedVisit('one', 2, '750000.00')]);
    $after = $calculator->calculate([v3PricedVisit('one', 2, '650000.00')]);

    expect($before['additionalWorkerFees'])->toBe('150000.00')
        ->and($after['additionalWorkerFees'])->toBe('150000.00')
        ->and($before['totalWithAdditionalWorkerFees'])->toBe('900000.00')
        ->and($after['totalWithAdditionalWorkerFees'])->toBe('800000.00')
        ->and($after['visits'][0]['feeCouponEligible'])->toBeFalse()
        ->and($after['visits'][0]['feeCommissionEligible'])->toBeFalse()
        ->and($after['visits'][0]['feeWorkerPayEligible'])->toBeFalse()
        ->and($after['visits'][0]['feeTravelEligible'])->toBeFalse();
});

test('the same visit cannot be counted twice even under two different positions', function (): void {
    $calculator = new CleaningV3AdditionalWorkerFeeCalculator;

    expect(fn () => $calculator->calculate([
        v3PricedVisit('repeated', 2),
        v3PricedVisit('repeated', 2),
    ]))->toThrow(DomainException::class, 'DUPLICATE_OR_INVALID_VISIT_KEY');
});

test('financial inputs require canonical string amounts rather than floats or client pricing fields', function (): void {
    $calculator = new CleaningV3AdditionalWorkerFeeCalculator;
    foreach (['-1', '123.456', '1e9', '001', '9999999999999', '', 'NaN'] as $amount) {
        expect(fn () => $calculator->calculate([v3PricedVisit('visit', 2, $amount)]))
            ->toThrow(DomainException::class, 'INVALID_APPROVED_NET_AMOUNT');
    }

    $bad = v3PricedVisit('visit', 2);
    $bad['netBeforeAdditionalWorkerFee'] = 650000.0;
    expect(fn () => $calculator->calculate([$bad]))
        ->toThrow(DomainException::class, 'INVALID_APPROVED_NET_AMOUNT');

    $bad = v3PricedVisit('visit', 2);
    $bad['discountOnWorkerFee'] = '150000.00';
    expect(fn () => $calculator->calculate([$bad]))
        ->toThrow(DomainException::class, 'INVALID_PRICED_VISIT');
});

test('unpriced visits and invalid priced worker counts cannot be used to fake zero fees', function (): void {
    $calculator = new CleaningV3AdditionalWorkerFeeCalculator;

    expect(fn () => $calculator->calculate([]))
        ->toThrow(DomainException::class, 'INVALID_PRICED_VISITS');
    expect(fn () => $calculator->calculate([v3PricedVisit('visit', 0)]))
        ->toThrow(DomainException::class, 'INVALID_PRICED_WORKER_COUNT');
    expect(fn () => $calculator->calculate([v3PricedVisit('visit', 21)]))
        ->toThrow(DomainException::class, 'INVALID_PRICED_WORKER_COUNT');
});

test('minor SYP amounts remain exact without floating point rounding', function (): void {
    $result = (new CleaningV3AdditionalWorkerFeeCalculator)->calculate([
        v3PricedVisit('a', 2, '0.01'),
        v3PricedVisit('b', 1, '0.02'),
        v3PricedVisit('c', 3, '0.03'),
    ]);
    expect($result['netBeforeAdditionalWorkerFees'])->toBe('0.06')
        ->and($result['additionalWorkerFees'])->toBe('450000.00')
        ->and($result['totalWithAdditionalWorkerFees'])->toBe('450000.06');
});

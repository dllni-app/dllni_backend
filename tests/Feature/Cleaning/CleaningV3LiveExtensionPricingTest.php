<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3LiveExtensionPricing;
use Modules\Cleaning\Services\CleaningV3TreasuryAllocationVerifier;

function extensionPricingFixture(bool $event): array
{
    $row = [
        'occurrenceKey' => 'visit-a', 'appointmentKey' => 'a', 'pricedWorkers' => 2,
        'baseAmountSyp' => '200000.00', 'addonsAmountSyp' => '0.00',
        'equipmentAmountSyp' => '0.00', 'supervisorAmountSyp' => '0.00',
        'minimumTopUpSyp' => '0.00', 'travelAmountSyp' => '2000.00',
        'eligibleBeforeDiscountSyp' => '200000.00', 'discountAmountSyp' => '10000.00',
        'additionalWorkerFeeSyp' => '150000.00', 'totalAmountSyp' => '342000.00',
    ];
    return [
        'commercialReview' => ['visits' => [$row]],
        'settlementBasis' => [
            'version' => 1, 'commissionType' => 'percent', 'commission' => '10.00', 'supervisorHourlyCompensation' => '3000.00',
            'visits' => ['visit-a' => [
                'billingMode' => $event ? 'event' : 'task', 'plannedMinutes' => 120,
                'materialAmountSyp' => '0.00', 'eventRateSyp' => $event ? '50000.00' : null,
                'eventRateMinutes' => $event ? 60 : null,
            ]],
        ],
    ];
}

test('live extension preserves fixed task price and worker fee while increasing funded supervisor time', function (): void {
    $service = app(CleaningV3LiveExtensionPricing::class);
    $quote = $service->revise(extensionPricingFixture(false), 'visit-a', 30);
    expect($quote['newMinutes'])->toBe(150)
        ->and($quote['additionalAmountSyp'])->toBe('0.00')
        ->and($quote['newTotalSyp'])->toBe('342000.00')
        ->and($quote['additionalWorkerFeeSyp'])->toBe('150000.00')
        ->and($quote['discountAmountSyp'])->toBe('10000.00')
        ->and($quote['supervisorObligationSyp'])->toBe('7500.00')
        ->and($quote['requiredFundingSyp'])->toBe('209500.00');
});

test('event extension uses the original rate and cannot charge a second worker fee or discount', function (): void {
    $service = app(CleaningV3LiveExtensionPricing::class);
    $quote = $service->revise(extensionPricingFixture(true), 'visit-a', 30);
    expect($quote['newMinutes'])->toBe(150)
        ->and($quote['additionalAmountSyp'])->toBe('50000.00')
        ->and($quote['newTotalSyp'])->toBe('392000.00')
        ->and($quote['requiredFundingSyp'])->toBe('252000.00')
        ->and($quote['additionalWorkerFeeSyp'])->toBe('150000.00');
    expect(fn () => $service->revise(extensionPricingFixture(true), 'visit-a', 390))->toThrow(DomainException::class);
    expect(fn () => $service->revise(extensionPricingFixture(true), 'visit-a', 15))->toThrow(DomainException::class);
});

test('unconfigured independent treasury cannot authorize any allocation', function (): void {
    config()->set('cleaning_v3_catalog.treasury_provider', null);
    config()->set('cleaning_v3_catalog.treasury_public_key', null);
    expect(fn () => app(CleaningV3TreasuryAllocationVerifier::class)->verify([]))
        ->toThrow(DomainException::class, 'V3_TREASURY_VERIFIER_NOT_CONFIGURED');
});

test('shortened event coupon remains limited by the final company commission', function (): void {
    $snapshot = extensionPricingFixture(true);
    $snapshot['settlementBasis']['couponCommissionCapped'] = true;
    $snapshot['commercialReview']['visits'][0]['discountAmountSyp'] = '20000.00';
    $snapshot['commercialReview']['visits'][0]['totalAmountSyp'] = '332000.00';
    $calc = app(\Modules\Cleaning\Services\CleaningV3CashSettlementCalculator::class)->calculate(
        $snapshot, 'visit-a', 3600, [
            ['workerId' => 1, 'activeSeconds' => 3600],
            ['workerId' => 2, 'activeSeconds' => 3600],
        ],
    );
    expect($calc['discountAmountSyp'])->toBe('10000.00')
        ->and($calc['commissionReceivableSyp'])->toBe('10000.00')
        ->and($calc['additionalWorkerFeeSyp'])->toBe('150000.00')
        ->and($calc['totalDueSyp'])->toBe('242000.00');
    $snapshot['commercialReview']['visits'][0]['discountAmountSyp'] = '20000.01';
    expect(fn () => app(\Modules\Cleaning\Services\CleaningV3CashSettlementBasis::class)->assertValid($snapshot))
        ->toThrow(DomainException::class, 'V3_COUPON_EXCEEDS_COMMISSION');
});

test('team visit adds company commission above wages and freezes one supervisor fee through extension', function (): void {
    $snapshot = extensionPricingFixture(false);
    $snapshot['settlementBasis']['couponCommissionCapped'] = true;
    $snapshot['settlementBasis']['visits']['visit-a']['commissionAdded'] = true;
    $snapshot['settlementBasis']['visits']['visit-a']['supervisorVisitCompensation'] = '15000.00';
    $row = &$snapshot['commercialReview']['visits'][0];
    $row['supervisorAmountSyp'] = '15000.00';
    $row['commissionAmountSyp'] = '20000.00';
    $row['eligibleBeforeDiscountSyp'] = '235000.00';
    $row['totalAmountSyp'] = '377000.00';
    unset($row);
    $calculator = app(\Modules\Cleaning\Services\CleaningV3CashSettlementCalculator::class);
    $calc = $calculator->calculate($snapshot, 'visit-a', 3600, [
        ['workerId' => 1, 'activeSeconds' => 3600],
        ['workerId' => 2, 'activeSeconds' => 3600],
    ], 3600);
    expect($calc['totalDueSyp'])->toBe('377000.00')
        ->and($calc['supervisorPayableSyp'])->toBe('15000.00')
        ->and($calc['companyCommissionSyp'])->toBe('20000.00')
        ->and($calc['companyNetCommissionSyp'])->toBe('10000.00')
        ->and($calc['companyCashDueSyp'])->toBe('160000.00')
        ->and($calc['commissionReceivableSyp'])->toBe('0.00')
        ->and(array_column($calc['workerPayables'], 'grossPayableSyp'))->toBe(['101000.00', '101000.00'])
        ->and(array_column($calc['workerPayables'], 'commissionReceivableSyp'))->toBe(['0.00', '0.00']);
    $extension = app(CleaningV3LiveExtensionPricing::class)->revise($snapshot, 'visit-a', 30);
    expect($extension['newTotalSyp'])->toBe('377000.00')
        ->and($extension['supervisorObligationSyp'])->toBe('15000.00')
        ->and($extension['requiredFundingSyp'])->toBe('217000.00');
    $snapshot['commercialReview']['visits'][0]['commissionAmountSyp'] = '1.00';
    expect(fn () => $calculator->calculate($snapshot, 'visit-a', 3600, [
        ['workerId' => 1, 'activeSeconds' => 3600],
        ['workerId' => 2, 'activeSeconds' => 3600],
    ], 3600))->toThrow(DomainException::class, 'INVALID_V3_TEAM_CASH_POLICY');
});

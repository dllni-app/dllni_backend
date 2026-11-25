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

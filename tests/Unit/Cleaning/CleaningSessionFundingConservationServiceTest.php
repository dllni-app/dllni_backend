<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningSessionFundingConservationService;

it('identifies solo booking 80 travel gap without inventing platform funding', function (): void {
    $result = (new CleaningSessionFundingConservationService())->assess('150.00', [
        ['workerAmount' => '160.00', 'travelFee' => '10.00'],
    ], 1);

    expect($result['status'])->toBe('blocked')
        ->and($result['workerObligations'])->toBe('160.00')
        ->and($result['workerTravelIncluded'])->toBe('10.00')
        ->and($result['minimumUnfunded'])->toBe('10.00')
        ->and($result['fundingProven'])->toBeFalse();
});

it('identifies booking 84 group travel gap without counting travel twice', function (): void {
    $result = (new CleaningSessionFundingConservationService())->assess('150.00', [
        ['workerAmount' => '122.00', 'travelFee' => '47.00'],
        ['workerAmount' => '85.00', 'travelFee' => '10.00'],
    ], 2);

    expect($result['workerObligations'])->toBe('207.00')
        ->and($result['workerTravelIncluded'])->toBe('57.00')
        ->and($result['minimumUnfunded'])->toBe('57.00')
        ->and($result['fundingProven'])->toBeFalse();
});

it('requires actual approved funding evidence even when a numeric allocation balances', function (): void {
    $service = new CleaningSessionFundingConservationService();
    $workers = [['workerAmount' => '160.00', 'travelFee' => '10.00']];

    expect($service->assess('150.00', $workers, 1, '0.00', '10.00')['fundingProven'])->toBeFalse()
        ->and($service->assess('150.00', $workers, 1, '0.00', '10.00', 'approved:example')['fundingProven'])->toBeFalse()
        ->and($service->assess('150.00', $workers, 1, '0.00', '9.99', 'approved:example', true)['minimumUnfunded'])->toBe('0.01')
        ->and($service->assess('150.00', $workers, 1, '0.00', '10.00', 'approved:example', true)['status'])->toBe('balanced');
});

it('fails closed for missing workers, unconfigured supervisor and malformed currency', function (): void {
    $service = new CleaningSessionFundingConservationService();

    expect($service->assess('150.00', [], 2)['minimumUnfunded'])->toBeNull()
        ->and($service->assess('150.00', [['workerAmount' => '160.001', 'travelFee' => '10.00']], 1)['fundingProven'])->toBeFalse()
        ->and($service->assess('150.00', [['workerAmount' => '160.00', 'travelFee' => '10.00']], 1, null)['fundingProven'])->toBeFalse();
});

it('distinguishes conservation within a frozen quote from verified external funding', function (): void {
    $result = (new CleaningSessionFundingConservationService())->assess('1100.00', [
        ['workerAmount' => '900.00', 'travelFee' => '0.00'],
    ], 1);

    expect($result['status'])->toBe('within_quote')
        ->and($result['withinQuote'])->toBeTrue()
        ->and($result['fundingProven'])->toBeFalse();
});

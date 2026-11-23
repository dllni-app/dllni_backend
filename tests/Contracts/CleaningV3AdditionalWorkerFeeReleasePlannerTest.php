<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3AdditionalWorkerFeeReleasePlanner;

test('unstarted cancelled visit may release uncollected fee but never fabricate a refund', function (): void {
    $policy = new CleaningV3AdditionalWorkerFeeReleasePlanner;
    $result = $policy->review(15, 3, 'cancelled', false, '0.00', '0.00');

    expect($result['additionalWorkerFee'])->toBe('300000.00')
        ->and($result['uncollectedFeeReleaseCandidate'])->toBe('300000.00')
        ->and($result['postedFeeRefundReviewCeiling'])->toBe('0.00')
        ->and($result['automaticFeeReleaseEligible'])->toBeTrue()
        ->and($result['canReleaseFunding'])->toBeFalse()
        ->and($result['canRefund'])->toBeFalse();
});

test('collected surcharge of a skipped visit only creates a manually approved refund candidate', function (): void {
    $policy = new CleaningV3AdditionalWorkerFeeReleasePlanner;
    $result = $policy->review(15, 3, 'skipped', false, '200000.00', '50000.00');

    expect($result['additionalWorkerFee'])->toBe('300000.00')
        ->and($result['uncollectedFeeReleaseCandidate'])->toBe('100000.00')
        ->and($result['postedFeeRefundReviewCeiling'])->toBe('150000.00')
        ->and($result['manualFeeRefundReviewCeiling'])->toBe('150000.00')
        ->and($result['requiresApprovedOriginalCollectionReference'])->toBeTrue()
        ->and($result['requiresRefundReasonAndAuthorization'])->toBeTrue()
        ->and($result['canRefund'])->toBeFalse();
});

test('once work has started cancellation never automatically reduces worker surcharge', function (): void {
    $policy = new CleaningV3AdditionalWorkerFeeReleasePlanner;
    $result = $policy->review(15, 2, 'cancelled', true, '150000.00', '0.00');

    expect($result['automaticFeeReleaseEligible'])->toBeFalse()
        ->and($result['uncollectedFeeReleaseCandidate'])->toBe('0.00')
        ->and($result['postedFeeRefundReviewCeiling'])->toBe('0.00')
        ->and($result['manualFeeRefundReviewCeiling'])->toBe('150000.00')
        ->and($result['canRefund'])->toBeFalse();
});

test('worker replacement or a normal completed visit never triggers a new fee adjustment', function (): void {
    $policy = new CleaningV3AdditionalWorkerFeeReleasePlanner;
    foreach (['worker_assigned', 'completed', 'in_progress', 'paused'] as $status) {
        $result = $policy->review(15, 2, $status, true, '0.00', '0.00');
        expect($result['additionalWorkerFee'])->toBe('150000.00')
            ->and($result['automaticFeeReleaseEligible'])->toBeFalse()
            ->and($result['uncollectedFeeReleaseCandidate'])->toBe('0.00')
            ->and($result['canSettle'])->toBeFalse();
    }
});

test('zero additional-worker surcharge is always zero even on cancellation', function (): void {
    $result = (new CleaningV3AdditionalWorkerFeeReleasePlanner)
        ->review(15, 1, 'cancelled', false, '0.00', '0.00');

    expect($result['additionalWorkerFee'])->toBe('0.00')
        ->and($result['uncollectedFeeReleaseCandidate'])->toBe('0.00')
        ->and($result['manualFeeRefundReviewCeiling'])->toBe('0.00');
});

test('refund over collected amount or fee-specific collected amount above immutable fee is rejected', function (): void {
    $policy = new CleaningV3AdditionalWorkerFeeReleasePlanner;
    expect(fn () => $policy->review(15, 2, 'skipped', false, '150000.00', '150000.01'))
        ->toThrow(DomainException::class, 'INVALID_V3_FEE_LEDGER_EVIDENCE');
    expect(fn () => $policy->review(15, 2, 'cancelled', false, '150000.01', '0.00'))
        ->toThrow(DomainException::class, 'INVALID_V3_FEE_LEDGER_EVIDENCE');
});

test('invalid visits, worker counts and noncanonical ledger amounts fail closed', function (): void {
    $policy = new CleaningV3AdditionalWorkerFeeReleasePlanner;
    expect(fn () => $policy->review(0, 2, 'cancelled', false, '0.00', '0.00'))
        ->toThrow(DomainException::class, 'INVALID_V3_FEE_RELEASE_CONTEXT');
    expect(fn () => $policy->review(15, 0, 'cancelled', false, '0.00', '0.00'))
        ->toThrow(DomainException::class, 'INVALID_V3_FEE_RELEASE_CONTEXT');
    expect(fn () => $policy->review(15, 2, 'unknown', false, '0.00', '0.00'))
        ->toThrow(DomainException::class, 'INVALID_V3_FEE_RELEASE_CONTEXT');

    foreach (['-1', '1e5', '001', '1.999', 'NaN', '1000000000000'] as $amount) {
        expect(fn () => $policy->review(15, 2, 'skipped', false, $amount, '0.00'))
            ->toThrow(DomainException::class, 'INVALID_V3_FEE_LEDGER_AMOUNT');
    }
});

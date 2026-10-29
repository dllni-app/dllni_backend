<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningProviderClearingEvidenceContract;

it('rejects a superficially complete caller supplied clearing record', function (): void {
    $result = app(CleaningProviderClearingEvidenceContract::class)->assess([
        'provider' => 'example',
        'providerTransactionId' => 'tx-1',
        'platformAccountId' => 'platform-1',
        'currency' => 'SYP',
        'clearedAt' => '2026-10-29T00:00:00Z',
        'sourceDigest' => str_repeat('a', 64),
        'grossAmount' => '500.00',
        'ownerScope' => 'cleaning_platform',
        'verificationState' => 'cleared',
    ]);

    expect($result['verified'])->toBeFalse()
        ->and($result['reasonCodes'])->toContain('independent_provider_verification_unavailable');
});

it('reports malformed financial claims without granting funds', function (): void {
    $result = app(CleaningProviderClearingEvidenceContract::class)->assess([
        'grossAmount' => '-10',
        'ownerScope' => 'worker_deposit',
        'sourceDigest' => 'fake',
    ]);

    expect($result['verified'])->toBeFalse()
        ->and($result['reasonCodes'])->toContain('invalid_gross_amount', 'invalid_source_digest', 'owner_scope_unverified');
});

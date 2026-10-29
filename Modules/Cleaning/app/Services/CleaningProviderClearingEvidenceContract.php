<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/**
 * Provider-neutral requirements for a future independent clearing adapter.
 * Caller-supplied records are untrusted, even when their fields look valid.
 */
final class CleaningProviderClearingEvidenceContract
{
    /** @return array{verified:bool,reasonCodes:list<string>,requirements:list<string>} */
    public function assess(array $claim): array
    {
        $reasons = ['independent_provider_verification_unavailable'];
        foreach (['provider', 'providerTransactionId', 'platformAccountId', 'currency', 'clearedAt', 'sourceDigest'] as $field) {
            if (! isset($claim[$field]) || ! is_string($claim[$field]) || mb_trim($claim[$field]) === '') {
                $reasons[] = 'missing_'.$field;
            }
        }
        if (! isset($claim['sourceDigest']) || ! is_string($claim['sourceDigest'])
            || ! preg_match('/^[a-f0-9]{64}$/iD', $claim['sourceDigest'])) {
            $reasons[] = 'invalid_source_digest';
        }
        if (! isset($claim['grossAmount']) || ! is_string($claim['grossAmount'])
            || ! preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/D', $claim['grossAmount'])
            || (float) $claim['grossAmount'] <= 0) {
            $reasons[] = 'invalid_gross_amount';
        }
        if (($claim['ownerScope'] ?? null) !== 'cleaning_platform') {
            $reasons[] = 'owner_scope_unverified';
        }

        return [
            'verified' => false,
            'reasonCodes' => $reasons,
            'requirements' => [
                'independent_authenticated_provider_lookup',
                'platform_account_ownership_reconciliation',
                'settlement_and_reversal_reconciliation',
                'transaction_uniqueness_and_currency_match',
                'durable_atomic_reservation_and_obligation_coverage',
            ],
        ];
    }
}

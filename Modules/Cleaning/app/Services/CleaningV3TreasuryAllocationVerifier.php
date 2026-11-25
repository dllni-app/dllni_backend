<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;

/** Verification only; this server never possesses the treasury signing key. */
final class CleaningV3TreasuryAllocationVerifier
{
    public function verify(array $envelope, ?CarbonImmutable $now = null): array
    {
        $key = $this->decode(config('cleaning_v3_catalog.treasury_public_key'), 32);
        $provider = config('cleaning_v3_catalog.treasury_provider');
        if ($key === null || ! is_string($provider) || $provider === '' || ! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new DomainException('V3_TREASURY_VERIFIER_NOT_CONFIGURED');
        }
        $p = $envelope['payload'] ?? null;
        $sig = $this->decode($envelope['signature'] ?? null, 64);
        $fields = ['schemaVersion', 'provider', 'owner', 'currency', 'creditId', 'allocationId',
            'bookingId', 'visitId', 'quoteId', 'pricingRevision', 'pricingDigest',
            'grossCreditSyp', 'reservedSyp', 'issuedAt', 'expiresAt', 'state', 'supersedesAllocationId'];
        if (count($envelope) !== 2 || ! is_array($p) || count($p) !== count($fields)
            || array_diff($fields, array_keys($p)) !== [] || $sig === null
            || $p['schemaVersion'] !== 1 || $p['provider'] !== $provider || $p['owner'] !== 'cleaning_platform'
            || $p['currency'] !== 'SYP' || $p['state'] !== 'reserved'
            || ! is_string($p['creditId']) || preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $p['creditId']) !== 1
            || ! is_string($p['allocationId']) || ! \Illuminate\Support\Str::isUuid($p['allocationId'])
            || ($p['supersedesAllocationId'] !== null && (! is_string($p['supersedesAllocationId']) || ! \Illuminate\Support\Str::isUuid($p['supersedesAllocationId']) || $p['supersedesAllocationId'] === $p['allocationId']))
            || ! is_string($p['quoteId']) || ! \Illuminate\Support\Str::isUuid($p['quoteId'])
            || ! is_string($p['pricingDigest']) || preg_match('/^[a-f0-9]{64}$/D', $p['pricingDigest']) !== 1) {
            throw new DomainException('V3_TREASURY_ALLOCATION_INVALID');
        }
        foreach (['bookingId', 'visitId', 'pricingRevision'] as $f) {
            if (! is_int($p[$f]) || $p[$f] < 1) {
                throw new DomainException('V3_TREASURY_ALLOCATION_INVALID');
            }
        }
        $gross = CleaningV3CashSettlementBasis::cents($p['grossCreditSyp']);
        $reserved = CleaningV3CashSettlementBasis::cents($p['reservedSyp']);
        if ($reserved < 1 || $reserved > $gross) {
            throw new DomainException('V3_TREASURY_ALLOCATION_INVALID');
        }
        $issued = $this->timestamp($p['issuedAt']);
        $expires = $this->timestamp($p['expiresAt']);
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        // A verified allocation is intentionally short-lived. The external
        // custodian must keep the funds reserved and renew before further work.
        if ($issued > $now->addSeconds(30) || $expires <= $now || $expires <= $issued
            || $expires > $issued->addHours(24)) {
            throw new DomainException('V3_TREASURY_ALLOCATION_EXPIRED');
        }
        ksort($p);
        if (! sodium_crypto_sign_verify_detached($sig, json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key)) {
            throw new DomainException('V3_TREASURY_ALLOCATION_SIGNATURE_INVALID');
        }
        return $p;
    }

    private function decode(mixed $value, int $size): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) && strlen($decoded) === $size ? $decoded : null;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new DomainException('V3_TREASURY_ALLOCATION_INVALID');
        }
        try {
            $at = CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            throw new DomainException('V3_TREASURY_ALLOCATION_INVALID');
        }
        if ($at->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new DomainException('V3_TREASURY_ALLOCATION_INVALID');
        }
        return $at;
    }
}

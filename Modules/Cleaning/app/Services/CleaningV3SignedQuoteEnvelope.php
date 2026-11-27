<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Internal V3 quote envelope integrity codec.
 *
 * A successful HMAC is NOT proof of a funded booking, a published tariff,
 * customer acceptance, stock availability or a posted collection. The
 * publisher/pricer/financial admission services must prove those facts before
 * issuing an envelope and AGAIN inside a locked booking-create transaction.
 * No public issue route calls this codec while financial approval is pending.
 */
final class CleaningV3SignedQuoteEnvelope
{
    /**
     * @param  array<string,mixed>  $serverApprovedSnapshot
     * @return array{quoteId:string,customerId:int,issuedAt:string,expiresAt:string,snapshot:array<string,mixed>,signature:string}
     */
    public function seal(
        int $customerId,
        array $serverApprovedSnapshot,
        int $lifetimeMinutes = 15,
        ?CarbonImmutable $now = null,
    ): array {
        if ($customerId < 1 || $lifetimeMinutes < 1 || $lifetimeMinutes > 60) {
            throw new DomainException('INVALID_V3_QUOTE_ISSUE_PARAMETERS');
        }

        $this->validateSnapshot($serverApprovedSnapshot);
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $payload = [
            'quoteId' => (string) Str::uuid(),
            'customerId' => $customerId,
            'issuedAt' => $now->toIso8601String(),
            'expiresAt' => $now->addMinutes($lifetimeMinutes)->toIso8601String(),
            'snapshot' => $serverApprovedSnapshot,
        ];

        return [...$payload, 'signature' => $this->mac($payload)];
    }

    /**
     * Verify against the CURRENT approved published catalog revision.
     * Caller must fetch the persisted quote and check its idempotency record
     * under lock; an HMAC is deliberately insufficient to create a booking.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function verify(
        array $envelope,
        int $expectedCustomerId,
        string $expectedCatalogKey,
        string $expectedPublishedDigest,
        ?CarbonImmutable $now = null,
    ): array {
        if ($expectedCustomerId < 1 || ! $this->key($expectedCatalogKey)
            || ! $this->digest($expectedPublishedDigest)
            || array_diff(array_keys($envelope), [
                'quoteId', 'customerId', 'issuedAt', 'expiresAt', 'snapshot', 'signature',
            ]) !== []) {
            throw new DomainException('INVALID_V3_QUOTE_ENVELOPE');
        }

        $signature = $envelope['signature'] ?? null;
        $signed = $envelope;
        unset($signed['signature']);
        if (! is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            || ! is_string($signed['quoteId'] ?? null)
            || ! Str::isUuid($signed['quoteId'])
            || ! is_int($signed['customerId'] ?? null)
            || $signed['customerId'] !== $expectedCustomerId
            || ! is_array($signed['snapshot'] ?? null)) {
            throw new DomainException('INVALID_V3_QUOTE_ENVELOPE');
        }

        $snapshot = $signed['snapshot'];
        $this->validateSnapshot($snapshot);
        if ($snapshot['catalogVersionKey'] !== $expectedCatalogKey
            || ! hash_equals($snapshot['catalogRevision'], $expectedPublishedDigest)) {
            throw new DomainException('STALE_V3_CATALOG_QUOTE');
        }

        $issued = $this->timestamp($signed['issuedAt'] ?? null);
        $expires = $this->timestamp($signed['expiresAt'] ?? null);
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();

        if ($expires <= $issued || $issued > $now->addMinutes(2)
            || $expires > $issued->addMinutes(60)) {
            throw new DomainException('INVALID_V3_QUOTE_LIFETIME');
        }
        if ($expires <= $now) {
            throw new DomainException('V3_QUOTE_EXPIRED');
        }
        if (! hash_equals($this->mac($signed), $signature)) {
            throw new DomainException('V3_QUOTE_SIGNATURE_MISMATCH');
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function validateSnapshot(array $snapshot): void
    {
        if (array_is_list($snapshot) || array_diff(array_keys($snapshot), [
            'contractVersion', 'catalogVersionKey', 'catalogRevision',
            'draftFingerprint', 'pricingRevision', 'currency',
            'visits', 'eligibleBaseTotal', 'discountTotal',
            'netBeforeAdditionalWorkerFees', 'additionalWorkerFees',
            'totalWithAdditionalWorkerFees', 'commercialReview', 'paymentMethod', 'paymentStatus', 'settlementBasis', 'amendment', 'dailyAppointments', 'workRequirementsDigests',
        ]) !== [] || ($snapshot['contractVersion'] ?? null) !== 3) {
            throw new DomainException('INVALID_V3_QUOTE_SNAPSHOT');
        }

        if (! $this->key($snapshot['catalogVersionKey'] ?? null)
            || ! $this->digest($snapshot['catalogRevision'] ?? null)
            || ! $this->digest($snapshot['draftFingerprint'] ?? null)
            || ! is_int($snapshot['pricingRevision'] ?? null)
            || $snapshot['pricingRevision'] < 1
            || ($snapshot['currency'] ?? null) !== 'SYP'
            || ! is_array($snapshot['visits'] ?? null)
            || ! array_is_list($snapshot['visits']) || $snapshot['visits'] === []
            || count($snapshot['visits']) > 90) {
            throw new DomainException('INVALID_V3_QUOTE_SNAPSHOT');
        }

        if (array_key_exists('amendment', $snapshot)) {
            $amendment = $snapshot['amendment'];
            if (! is_array($amendment) || count($amendment) !== 3
                || ! is_int($amendment['bookingId'] ?? null) || $amendment['bookingId'] < 1
                || ! is_string($amendment['previousQuoteId'] ?? null) || ! Str::isUuid($amendment['previousQuoteId'])
                || ! is_int($amendment['previousRevision'] ?? null) || $amendment['previousRevision'] < 1
                || $snapshot['pricingRevision'] !== $amendment['previousRevision'] + 1) {
                throw new DomainException('INVALID_V3_QUOTE_AMENDMENT');
            }
        }
        $commercial = array_key_exists('commercialReview', $snapshot);
        if (array_key_exists('paymentMethod', $snapshot) || array_key_exists('paymentStatus', $snapshot)) {
            if (! $commercial || ($snapshot['paymentMethod'] ?? null) !== 'cash_on_delivery'
                || ($snapshot['paymentStatus'] ?? null) !== 'unpaid') {
                throw new DomainException('INVALID_V3_QUOTE_PAYMENT_POLICY');
            }
        }
        $totals = [
            'base' => 0,
            'discount' => 0,
            'net' => 0,
            'fee' => 0,
            'total' => 0,
        ];
        $keys = [];
        $occurrences = [];
        foreach ($snapshot['visits'] as $visit) {
            if (! is_array($visit) || array_is_list($visit)
                || array_diff(array_keys($visit), [
                    'visitKey', 'appointmentKey', 'occurrenceKey',
                    'priceRevision', 'pricedWorkers', 'eligibleNetBeforeDiscount',
                    'discountAllocated', 'netBeforeAdditionalWorkerFee',
                    'additionalWorkerFee', 'totalWithAdditionalWorkerFee',
                ]) !== []) {
                throw new DomainException('INVALID_V3_QUOTE_VISIT');
            }

            $key = $visit['visitKey'] ?? null;
            $occurrence = $visit['occurrenceKey'] ?? null;
            if (! $this->key($key) || ! $this->key($occurrence)
                || ! $this->key($visit['appointmentKey'] ?? null)
                || isset($keys[$key]) || isset($occurrences[$occurrence])
                || ! is_int($visit['priceRevision'] ?? null)
                || $visit['priceRevision'] !== $snapshot['pricingRevision']
                || ! is_int($visit['pricedWorkers'] ?? null)
                || $visit['pricedWorkers'] < 1 || $visit['pricedWorkers'] > 20) {
                throw new DomainException('INVALID_V3_QUOTE_VISIT');
            }
            $keys[$key] = true;
            $occurrences[$occurrence] = true;

            $base = $this->cents($visit['eligibleNetBeforeDiscount'] ?? null);
            $discount = $this->cents($visit['discountAllocated'] ?? null);
            $net = $this->cents($visit['netBeforeAdditionalWorkerFee'] ?? null);
            $fee = $this->cents($visit['additionalWorkerFee'] ?? null);
            $total = $this->cents($visit['totalWithAdditionalWorkerFee'] ?? null);
            if ($discount > $base || (! $commercial && $net !== $base - $discount)
                || $fee !== ($visit['pricedWorkers'] - 1) * 15000000
                || $total !== $net + $fee) {
                throw new DomainException('INVALID_V3_QUOTE_VISIT_TOTALS');
            }
            $totals['base'] += $base;
            $totals['discount'] += $discount;
            $totals['net'] += $net;
            $totals['fee'] += $fee;
            $totals['total'] += $total;
        }

        $checks = [
            'eligibleBaseTotal' => 'base',
            'discountTotal' => 'discount',
            'netBeforeAdditionalWorkerFees' => 'net',
            'additionalWorkerFees' => 'fee',
            'totalWithAdditionalWorkerFees' => 'total',
        ];
        foreach ($checks as $field => $name) {
            if ($this->cents($snapshot[$field] ?? null) !== $totals[$name]) {
                throw new DomainException('INVALID_V3_QUOTE_TOTALS');
            }
        }
        if ($commercial) {
            app(CleaningV3CommercialQuoteSnapshot::class)->assertMatches($snapshot);
        }
        if (array_key_exists('settlementBasis', $snapshot)) {
            if (($snapshot['paymentMethod'] ?? null) !== 'cash_on_delivery') {
                throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
            }
            app(CleaningV3CashSettlementBasis::class)->assertValid($snapshot);
        }
        if (array_key_exists('dailyAppointments', $snapshot)) {
            app(CleaningV3DailyQuoteScope::class)->validate($snapshot);
        }
        if (array_key_exists('workRequirementsDigests', $snapshot)) {
            app(CleaningV3WorkRequirements::class)->assertCoverage($snapshot);
        }
        if ($totals['total'] > 99999999999999) {
            throw new DomainException('INVALID_V3_QUOTE_TOTALS');
        }
    }

    private function key(mixed $key): bool
    {
        return is_string($key)
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D', $key) === 1;
    }

    private function digest(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function cents(mixed $amount): int
    {
        if (! is_string($amount)
            || preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw new DomainException('INVALID_V3_QUOTE_MONEY');
        }
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $units * 100) + (int) mb_str_pad($fraction, 2, '0');
    }

    private function timestamp(mixed $input): CarbonImmutable
    {
        if (! is_string($input) || mb_strlen($input) > 45) {
            throw new DomainException('INVALID_V3_QUOTE_LIFETIME');
        }
        try {
            return CarbonImmutable::parse($input)->utc();
        } catch (Throwable) {
            throw new DomainException('INVALID_V3_QUOTE_LIFETIME');
        }
    }

    /** @param array<string,mixed> $signedPayload */
    private function mac(array $signedPayload): string
    {
        if ($signedPayload['snapshot']['catalogVersionKey'] === CleaningV3QaEnvironment::CATALOG_KEY) {
            $qaKey = app(CleaningV3QaEnvironment::class)->signingKey();
            if ($qaKey === null) {
                throw new DomainException('V3_QA_SIGNING_UNAVAILABLE');
            }

            return hash_hmac('sha256', "cleaning-v3-qa\n".$this->canonical($signedPayload), $qaKey);
        }

        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new DomainException('V3_SIGNING_KEY_UNAVAILABLE');
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(mb_substr($key, 7), true);
            if (! is_string($decoded) || mb_strlen($decoded) < 32) {
                throw new DomainException('V3_SIGNING_KEY_UNAVAILABLE');
            }
            $key = $decoded;
        } elseif (mb_strlen($key) < 32) {
            throw new DomainException('V3_SIGNING_KEY_UNAVAILABLE');
        }

        return hash_hmac('sha256', $this->canonical($signedPayload), $key);
    }

    private function canonical(mixed $value): string
    {
        $normalized = function (mixed $item) use (&$normalized): mixed {
            if (is_float($item)) {
                throw new DomainException('V3_FLOAT_FINANCIAL_DATA_FORBIDDEN');
            }
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalized, $item);
        };

        return json_encode(
            $normalized($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}

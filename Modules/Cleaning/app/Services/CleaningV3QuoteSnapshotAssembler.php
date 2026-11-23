<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Assembles an internally approved V3 pricing snapshot for the HMAC codec.
 *
 * The visit plan MUST be freshly recomputed by the server against the
 * published catalog, and amounts MUST originate from verified tariffs.
 * This pure assembler does not prove those facts, sign quotes, reserve funds,
 * create bookings or change any ledger record.
 */
final class CleaningV3QuoteSnapshotAssembler
{
    public function __construct(
        private readonly CleaningV3VisitDiscountAllocator $allocator,
    ) {}

    /**
     * @param  array<string,mixed>  $serverVerifiedPlan
     * @param  list<array{visitKey:string,eligibleNetBeforeDiscount:string}>  $serverPricedVisits
     * @param  array<string,mixed>|null  $approvedCoupon
     * @return array<string,mixed>
     */
    public function assemble(
        array $serverVerifiedPlan,
        array $serverPricedVisits,
        int $pricingRevision,
        ?array $approvedCoupon = null,
    ): array {
        if (($serverVerifiedPlan['contractVersion'] ?? null) !== 3
            || ! is_int($pricingRevision) || $pricingRevision < 1
            || $pricingRevision > 2147483647
            || ($serverVerifiedPlan['currency'] ?? 'SYP') !== 'SYP'
            || ! $this->key($serverVerifiedPlan['catalogVersionKey'] ?? null)
            || ! $this->digest($serverVerifiedPlan['catalogRevision'] ?? null)
            || ! $this->digest($serverVerifiedPlan['draftFingerprint'] ?? null)
            || ! is_array($serverVerifiedPlan['appointments'] ?? null)
            || ! array_is_list($serverVerifiedPlan['appointments'])
            || $serverVerifiedPlan['appointments'] === []) {
            throw new DomainException('INVALID_V3_VERIFIED_PLAN');
        }

        // A stale or modified schedule may not be priced against a hash
        // belonging to a different visit plan. Verify the exact fingerprint
        // emitted by the canonical server planner before money allocation.
        $fingerprintPayload = [
            'contractVersion' => 3,
            'catalogVersionKey' => $serverVerifiedPlan['catalogVersionKey'],
            'catalogRevision' => $serverVerifiedPlan['catalogRevision'],
            'timezone' => $serverVerifiedPlan['timezone'] ?? null,
            'appointments' => $serverVerifiedPlan['appointments'],
        ];
        $digest = hash('sha256', json_encode(
            $fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        if (! hash_equals($serverVerifiedPlan['draftFingerprint'], $digest)) {
            throw new DomainException('V3_PLAN_FINGERPRINT_MISMATCH');
        }

        $expected = [];
        $occurrences = [];
        foreach ($serverVerifiedPlan['appointments'] as $appointment) {
            if (! is_array($appointment)
                || ! $this->key($appointment['appointmentKey'] ?? null)
                || ! is_array($appointment['visits'] ?? null)
                || ! array_is_list($appointment['visits'])
                || $appointment['visits'] === []) {
                throw new DomainException('INVALID_V3_VERIFIED_PLAN');
            }

            foreach ($appointment['visits'] as $visit) {
                if (! is_array($visit)
                    || ! $this->key($visit['occurrenceKey'] ?? null)
                    || ! is_int($visit['requiredWorkers'] ?? null)
                    || $visit['requiredWorkers'] < 1 || $visit['requiredWorkers'] > 20
                    || ! is_int($visit['durationMinutes'] ?? null)
                    || $visit['durationMinutes'] < 1 || $visit['durationMinutes'] > 480
                    || isset($occurrences[$visit['occurrenceKey']])) {
                    throw new DomainException('INVALID_V3_VERIFIED_PLAN');
                }

                $key = $visit['occurrenceKey'];
                $occurrences[$key] = true;
                $expected[$key] = [
                    'visitKey' => $key,
                    'appointmentKey' => $appointment['appointmentKey'],
                    'occurrenceKey' => $key,
                    'priceRevision' => $pricingRevision,
                    'pricedWorkers' => $visit['requiredWorkers'],
                ];
                if (count($expected) > 90) {
                    throw new DomainException('INVALID_V3_VERIFIED_PLAN');
                }
            }
        }

        if (! array_is_list($serverPricedVisits)
            || count($serverPricedVisits) !== count($expected)) {
            throw new DomainException('V3_PRICING_VISIT_COVERAGE_MISMATCH');
        }
        $amounts = [];
        foreach ($serverPricedVisits as $line) {
            if (! is_array($line)
                || array_diff(array_keys($line), ['visitKey', 'eligibleNetBeforeDiscount']) !== []
                || ! $this->key($line['visitKey'] ?? null)
                || ! array_key_exists($line['visitKey'], $expected)
                || isset($amounts[$line['visitKey']])
                || ! is_string($line['eligibleNetBeforeDiscount'] ?? null)) {
                throw new DomainException('V3_PRICING_VISIT_COVERAGE_MISMATCH');
            }
            $amounts[$line['visitKey']] = $line['eligibleNetBeforeDiscount'];
        }

        $inputs = [];
        foreach ($expected as $key => $visit) {
            if (! array_key_exists($key, $amounts)) {
                throw new DomainException('V3_PRICING_VISIT_COVERAGE_MISMATCH');
            }
            $inputs[] = [
                'visitKey' => $key,
                'pricedWorkers' => $visit['pricedWorkers'],
                'eligibleNetBeforeDiscount' => $amounts[$key],
            ];
        }

        $allocated = $this->allocator->allocate($inputs, $approvedCoupon);
        $rows = [];
        foreach ($allocated['visits'] as $row) {
            $identity = $expected[$row['visitKey']];
            $rows[] = [
                ...$identity,
                'eligibleNetBeforeDiscount' => $row['eligibleNetBeforeDiscount'],
                'discountAllocated' => $row['discountAllocated'],
                'netBeforeAdditionalWorkerFee' => $row['netBeforeAdditionalWorkerFee'],
                'additionalWorkerFee' => $row['additionalWorkerFee'],
                'totalWithAdditionalWorkerFee' => $row['totalWithAdditionalWorkerFee'],
            ];
        }

        return [
            'contractVersion' => 3,
            'catalogVersionKey' => $serverVerifiedPlan['catalogVersionKey'],
            'catalogRevision' => $serverVerifiedPlan['catalogRevision'],
            'draftFingerprint' => $serverVerifiedPlan['draftFingerprint'],
            'pricingRevision' => $pricingRevision,
            'currency' => 'SYP',
            'visits' => $rows,
            'eligibleBaseTotal' => $allocated['eligibleBaseTotal'],
            'discountTotal' => $allocated['discountTotal'],
            'netBeforeAdditionalWorkerFees' => $allocated['netBeforeAdditionalWorkerFees'],
            'additionalWorkerFees' => $allocated['additionalWorkerFees'],
            'totalWithAdditionalWorkerFees' => $allocated['totalWithAdditionalWorkerFees'],
        ];
    }

    private function key(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D', $value) === 1;
    }

    private function digest(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}

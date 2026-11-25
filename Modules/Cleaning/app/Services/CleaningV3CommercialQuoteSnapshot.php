<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Exact signed-commercial-review structure. Internal only: validates amounts
 * and visit identities, not the provenance of a tariff or a cleared credit.
 * The caller MUST prove commercial and funding approval before issuing.
 */
final class CleaningV3CommercialQuoteSnapshot
{
    private const FIELDS = [
        'baseAmountSyp', 'addonsAmountSyp', 'equipmentAmountSyp', 'supervisorAmountSyp',
        'minimumTopUpSyp', 'travelAmountSyp', 'discountAmountSyp',
        'additionalWorkerFeeSyp', 'totalAmountSyp',
    ];

    public function assemble(array $canonicalPlan, array $review, string $reviewDraftFingerprint, int $revision): array
    {
        // Reuse the existing plan identity / canonical hash validator. No
        // customer-supplied visit money is accepted by this internal method.
        $zeros = [];
        foreach ($canonicalPlan['appointments'] ?? [] as $appointment) {
            foreach ($appointment['visits'] ?? [] as $visit) {
                $zeros[] = ['visitKey' => $visit['occurrenceKey'], 'eligibleNetBeforeDiscount' => '0.00'];
            }
        }
        $snapshot = app(CleaningV3QuoteSnapshotAssembler::class)->assemble($canonicalPlan, $zeros, $revision);
        $breakdown = [
            'draftFingerprint' => $reviewDraftFingerprint,
            'pricingFingerprint' => $review['pricingFingerprint'] ?? null,
            'couponCode' => $review['couponCode'] ?? null,
            'totals' => $review['totals'] ?? null,
            'visits' => $review['visits'] ?? null,
        ];
        $snapshot['commercialReview'] = $breakdown;
        $priced = $this->derive($snapshot);
        return [...$snapshot, ...$priced];
    }

    /** Validate the signed extension against independently derived totals. */
    public function assertMatches(array $snapshot): void
    {
        $derived = $this->derive($snapshot);
        foreach ($derived as $key => $value) {
            // HMAC canonicalizes associative keys, so compare by key/value
            // rather than PHP insertion order after a JSON database round trip.
            if (($snapshot[$key] ?? null) != $value) {
                throw new DomainException('V3_COMMERCIAL_QUOTE_TOTAL_MISMATCH');
            }
        }
    }

    private function derive(array $snapshot): array
    {
        $review = $snapshot['commercialReview'] ?? null;
        if (! is_array($review) || array_diff(array_keys($review), ['draftFingerprint', 'pricingFingerprint', 'couponCode', 'totals', 'visits']) !== []
            || ! $this->digest($review['draftFingerprint'] ?? null)
            || ! $this->digest($review['pricingFingerprint'] ?? null)
            || ! array_key_exists('couponCode', $review)
            || ($review['couponCode'] !== null && (! is_string($review['couponCode']) || mb_strlen($review['couponCode']) > 50 || trim($review['couponCode']) === ''))
            || ! is_array($review['visits'] ?? null) || ! array_is_list($review['visits'])
            || ! is_array($review['totals'] ?? null) || array_diff(array_keys($review['totals']), self::FIELDS) !== []
            || count($review['visits']) !== count($snapshot['visits'] ?? [])) {
            throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_REVIEW');
        }
        $expected = [];
        foreach ($snapshot['visits'] as $identity) {
            $key = $identity['occurrenceKey'] ?? null;
            if (! is_string($key) || isset($expected[$key]) || ($identity['visitKey'] ?? null) !== $key
                || ($identity['priceRevision'] ?? null) !== $snapshot['pricingRevision']) {
                throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_REVIEW');
            }
            $expected[$key] = $identity;
        }
        $sums = array_fill_keys(self::FIELDS, 0);
        $derived = [];
        $totalBase = 0;
        $net = 0;
        foreach ($review['visits'] as $row) {
            if (! is_array($row) || array_diff(array_keys($row), [...self::FIELDS, 'appointmentKey', 'occurrenceKey', 'pricedWorkers', 'eligibleBeforeDiscountSyp']) !== []
                || ! is_string($row['occurrenceKey'] ?? null) || ! isset($expected[$row['occurrenceKey']])) {
                throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_REVIEW');
            }
            $identity = $expected[$row['occurrenceKey']];
            unset($expected[$row['occurrenceKey']]);
            if ($identity['appointmentKey'] !== ($row['appointmentKey'] ?? null)
                || $identity['pricedWorkers'] !== ($row['pricedWorkers'] ?? null)) {
                throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_REVIEW');
            }
            $amounts = [];
            foreach (self::FIELDS as $field) {
                $amounts[$field] = $this->cents($row[$field] ?? null);
                $sums[$field] += $amounts[$field];
            }
            $eligible = $amounts['baseAmountSyp'] + $amounts['addonsAmountSyp'] + $amounts['equipmentAmountSyp']
                + $amounts['supervisorAmountSyp'] + $amounts['minimumTopUpSyp'];
            $discount = $amounts['discountAmountSyp'];
            $fee = $amounts['additionalWorkerFeeSyp'];
            $beforeFee = $eligible + $amounts['travelAmountSyp'] - $discount;
            if ($this->cents($row['eligibleBeforeDiscountSyp'] ?? null) !== $eligible || $discount > $eligible
                || ($review['couponCode'] === null && $discount !== 0)
                || $fee !== ($identity['pricedWorkers'] - 1) * 15000000
                || $amounts['totalAmountSyp'] !== $beforeFee + $fee) {
                throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_AMOUNTS');
            }
            $totalBase += $eligible;
            $net += $beforeFee;
            $derived[$row['occurrenceKey']] = [
                'visitKey' => $identity['visitKey'],
                'appointmentKey' => $identity['appointmentKey'],
                'occurrenceKey' => $identity['occurrenceKey'],
                'priceRevision' => $identity['priceRevision'],
                'pricedWorkers' => $identity['pricedWorkers'],
                'eligibleNetBeforeDiscount' => $this->money($eligible),
                'discountAllocated' => $this->money($discount),
                'netBeforeAdditionalWorkerFee' => $this->money($beforeFee),
                'additionalWorkerFee' => $this->money($fee),
                'totalWithAdditionalWorkerFee' => $this->money($amounts['totalAmountSyp']),
            ];
        }
        foreach (self::FIELDS as $field) {
            if ($this->cents($review['totals'][$field] ?? null) !== $sums[$field]) {
                throw new DomainException('V3_COMMERCIAL_QUOTE_TOTAL_MISMATCH');
            }
        }
        return [
            'visits' => array_map(static fn ($visit) => $derived[$visit['occurrenceKey']], $snapshot['visits']),
            'eligibleBaseTotal' => $this->money($totalBase),
            'discountTotal' => $this->money($sums['discountAmountSyp']),
            'netBeforeAdditionalWorkerFees' => $this->money($net),
            'additionalWorkerFees' => $this->money($sums['additionalWorkerFeeSyp']),
            'totalWithAdditionalWorkerFees' => $this->money($sums['totalAmountSyp']),
        ];
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function cents(mixed $amount): int
    {
        if (! is_string($amount) || preg_match('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D', $amount) !== 1) {
            throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_MONEY');
        }
        return (int) str_replace('.', '', $amount);
    }

    private function money(int $amount): string
    {
        if ($amount < 0 || $amount > 99999999999999) {
            throw new DomainException('INVALID_V3_COMMERCIAL_QUOTE_MONEY');
        }
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}

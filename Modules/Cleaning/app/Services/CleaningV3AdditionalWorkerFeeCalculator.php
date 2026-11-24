<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Deterministic V3 additional-worker fee math, calculated per visit.
 *
 * The supplied net must already include approved legacy pricing, travel,
 * commission and coupons. This fee is appended afterward: it is not eligible
 * for further coupons, commissions, worker/supervisor wages or travel fees.
 *
 * This read-only calculator is NOT a quote signer, payment receipt, funding
 * admission, approval or settlement writer. The priced worker count must
 * come from an approved immutable quote snapshot, never an accepted roster.
 */
final class CleaningV3AdditionalWorkerFeeCalculator
{
    private const FEE_CENTS_PER_ADDITIONAL_WORKER = 15000000;

    /**
     * @param  list<array<string, mixed>>  $visits
     * @return array<string, mixed>
     */
    public function calculate(array $visits): array
    {
        if (! array_is_list($visits) || $visits === [] || count($visits) > 90) {
            throw new DomainException('INVALID_PRICED_VISITS');
        }

        $known = [];
        $subtotalCents = 0;
        $surchargeCents = 0;
        $rows = [];

        foreach ($visits as $index => $visit) {
            if (! is_array($visit) || array_is_list($visit)
                || array_diff(array_keys($visit), [
                    'visitKey', 'pricedWorkers', 'netBeforeAdditionalWorkerFee',
                ]) !== []) {
                throw new DomainException('INVALID_PRICED_VISIT');
            }

            $key = $visit['visitKey'] ?? null;
            $workers = $visit['pricedWorkers'] ?? null;
            if (! is_string($key)
                || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D', $key) !== 1
                || isset($known[$key])) {
                throw new DomainException('DUPLICATE_OR_INVALID_VISIT_KEY');
            }
            if (! is_int($workers) || $workers < 1 || $workers > 20) {
                throw new DomainException('INVALID_PRICED_WORKER_COUNT');
            }

            $netCents = $this->parseAmount($visit['netBeforeAdditionalWorkerFee'] ?? null);
            $feeCents = ($workers - 1) * self::FEE_CENTS_PER_ADDITIONAL_WORKER;
            $subtotalCents += $netCents;
            $surchargeCents += $feeCents;
            $known[$key] = true;

            $rows[] = [
                'visitKey' => $key,
                'pricedWorkers' => $workers,
                'netBeforeAdditionalWorkerFee' => $this->money($netCents),
                'additionalWorkerFee' => $this->money($feeCents),
                'totalWithAdditionalWorkerFee' => $this->money($netCents + $feeCents),
                'feeCouponEligible' => false,
                'feeCommissionEligible' => false,
                'feeWorkerPayEligible' => false,
                'feeTravelEligible' => false,
            ];
        }

        return [
            'currency' => 'SYP',
            'visitCount' => count($rows),
            'netBeforeAdditionalWorkerFees' => $this->money($subtotalCents),
            'additionalWorkerFees' => $this->money($surchargeCents),
            'totalWithAdditionalWorkerFees' => $this->money($subtotalCents + $surchargeCents),
            'visits' => $rows,
            'previewOnly' => true,
            'canCharge' => false,
            'canSettle' => false,
        ];
    }

    /** Fee-only, non-binding estimate from a validated proposed visit plan. */
    public function previewPlan(array $appointments): array
    {
        $visits = [];
        foreach ($appointments as $appointment) {
            foreach ($appointment['visits'] as $visit) {
                $visits[] = [
                    'visitKey' => $visit['occurrenceKey'],
                    'pricedWorkers' => $visit['requiredWorkers'],
                    // Zero is only a placeholder for fee calculation; no base
                    // price or financial approval is inferred or returned.
                    'netBeforeAdditionalWorkerFee' => '0.00',
                ];
            }
        }
        $calculated = $this->calculate($visits);

        return [
            'currency' => 'SYP',
            'perAdditionalWorkerSyp' => $this->money(self::FEE_CENTS_PER_ADDITIONAL_WORKER),
            'totalEstimatedFeeSyp' => $calculated['additionalWorkerFees'],
            'visits' => array_map(static fn (array $row): array => [
                'occurrenceKey' => $row['visitKey'],
                'requestedWorkers' => $row['pricedWorkers'],
                'estimatedFeeSyp' => $row['additionalWorkerFee'],
            ], $calculated['visits']),
            'previewOnly' => true,
            'canCharge' => false,
        ];
    }

    private function parseAmount(mixed $amount): int
    {
        if (! is_string($amount)
            || preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw new DomainException('INVALID_APPROVED_NET_AMOUNT');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) mb_str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}

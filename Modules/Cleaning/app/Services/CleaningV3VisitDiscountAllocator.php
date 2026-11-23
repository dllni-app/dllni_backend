<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Pure V3 per-visit allocation of an approved booking-level coupon.
 *
 * The eligible base is AFTER all normal price, transport and commission math,
 * BEFORE the independent 150,000 SYP additional-worker surcharge. Fixed and
 * percentage coupons only affect the eligible base. Distribution uses largest
 * remainders with visit-key tie breaking so each cent is allocated exactly
 * once, regardless of input order.
 *
 * Does not authorize a quote, collect funds, update a booking or settle wages.
 */
final class CleaningV3VisitDiscountAllocator
{
    public function __construct(
        private readonly CleaningV3AdditionalWorkerFeeCalculator $workerFees,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $visits
     * @param  array<string, mixed>|null  $coupon
     * @return array<string, mixed>
     */
    public function allocate(array $visits, ?array $coupon = null): array
    {
        if (! array_is_list($visits) || $visits === [] || count($visits) > 90) {
            throw new DomainException('INVALID_V3_VISIT_DISCOUNT_INPUT');
        }

        if (! extension_loaded('bcmath')) {
            throw new DomainException('V3_EXACT_ARITHMETIC_UNAVAILABLE');
        }

        $bases = [];
        $keys = [];
        $totalBase = 0;
        foreach ($visits as $index => $visit) {
            if (! is_array($visit) || array_is_list($visit)
                || array_diff(array_keys($visit), [
                    'visitKey', 'pricedWorkers', 'eligibleNetBeforeDiscount',
                ]) !== []) {
                throw new DomainException('INVALID_V3_VISIT_DISCOUNT_INPUT');
            }

            $key = $visit['visitKey'] ?? null;
            $workers = $visit['pricedWorkers'] ?? null;
            if (! is_string($key) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}$/D', $key) !== 1
                || isset($keys[$key]) || ! is_int($workers) || $workers < 1 || $workers > 20) {
                throw new DomainException('INVALID_V3_VISIT_DISCOUNT_INPUT');
            }
            $base = $this->cents($visit['eligibleNetBeforeDiscount'] ?? null);
            if ($base < 0) {
                throw new DomainException('INVALID_V3_VISIT_DISCOUNT_INPUT');
            }

            $keys[$key] = true;
            $bases[$key] = ['base' => $base, 'workers' => $workers, 'index' => $index];
            $totalBase += $base;
        }

        $discountTotal = $this->discount($coupon, $totalBase);
        $allocated = array_fill_keys(array_keys($bases), 0);

        if ($discountTotal > 0 && $totalBase > 0) {
            $distributed = 0;
            $remainders = [];
            foreach ($bases as $key => $entry) {
                // PHP integer multiplication can overflow for valid SYP
                // amounts. Keep the whole numerator in exact decimal math.
                $numerator = bcmul((string) $entry['base'], (string) $discountTotal, 0);
                $allocated[$key] = (int) bcdiv($numerator, (string) $totalBase, 0);
                $remainders[$key] = (int) bcmod($numerator, (string) $totalBase);
                $distributed += $allocated[$key];
            }
            $remaining = $discountTotal - $distributed;
            $priorities = array_keys($bases);
            usort($priorities, static fn (string $a, string $b): int => ($remainders[$b] <=> $remainders[$a]) ?: strcmp($a, $b));
            for ($i = 0; $i < $remaining; $i++) {
                $allocated[$priorities[$i]]++;
            }
        }

        $priced = [];
        foreach ($bases as $key => $entry) {
            $priced[] = [
                'visitKey' => $key,
                'pricedWorkers' => $entry['workers'],
                'netBeforeAdditionalWorkerFee' => $this->money($entry['base'] - $allocated[$key]),
            ];
        }

        $workerQuote = $this->workerFees->calculate($priced);
        $rows = [];
        foreach ($workerQuote['visits'] as $row) {
            $key = $row['visitKey'];
            $rows[] = [
                ...$row,
                'eligibleNetBeforeDiscount' => $this->money($bases[$key]['base']),
                'discountAllocated' => $this->money($allocated[$key]),
                'couponAppliesToWorkerFee' => false,
            ];
        }

        return [
            'currency' => 'SYP',
            'visitCount' => count($rows),
            'eligibleBaseTotal' => $this->money($totalBase),
            'discountTotal' => $this->money($discountTotal),
            'netBeforeAdditionalWorkerFees' => $workerQuote['netBeforeAdditionalWorkerFees'],
            'additionalWorkerFees' => $workerQuote['additionalWorkerFees'],
            'totalWithAdditionalWorkerFees' => $workerQuote['totalWithAdditionalWorkerFees'],
            'visits' => $rows,
            'previewOnly' => true,
            'canQuote' => false,
            'canCharge' => false,
            'canSettle' => false,
        ];
    }

    /** @param array<string,mixed>|null $coupon */
    private function discount(?array $coupon, int $totalBase): int
    {
        if ($coupon === null) {
            return 0;
        }
        if (array_is_list($coupon) || array_diff(array_keys($coupon), ['type', 'amount']) !== []
            || ! in_array($coupon['type'] ?? null, ['fixed', 'percent'], true)) {
            throw new DomainException('INVALID_V3_COUPON');
        }
        $value = $this->cents($coupon['amount'] ?? null);
        if (($coupon['type'] === 'percent') && $value > 10000) {
            throw new DomainException('INVALID_V3_COUPON');
        }
        if ($coupon['type'] === 'fixed') {
            return min($totalBase, $value);
        }

        // Percentage amount is 0..100.00 stored as integer hundredths.
        // Coupon 12.50 means 1250/10000 of eligible base.
        return (int) bcdiv(bcadd(bcmul((string) $totalBase, (string) $value, 0), '5000', 0), '10000', 0);
    }

    private function cents(mixed $amount): int
    {
        if (! is_string($amount)
            || preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw new DomainException('INVALID_V3_SYP_DECIMAL');
        }

        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $units * 100) + (int) mb_str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}

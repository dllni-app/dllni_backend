<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Read-only V3 accounting invariant over ALREADY POSTED ledger movements.
 * No planned quote, pending payment or calculated entitlement is cash.
 *
 * Monetary values are parsed as integer cents, never using floating point;
 * a posted collection/refund must exactly equal its visit allocations for
 * that single immutable operation reference (not just a grand total).
 */
final class CleaningV3PostedAllocationConservation
{
    /**
     * @param  list<array<string, mixed>>  $postedMovements
     * @param  list<array<string, mixed>>  $allocations
     * @return array<string, mixed>
     */
    public function verify(array $postedMovements, array $allocations): array
    {
        $posted = [];
        foreach ($postedMovements as $index => $movement) {
            $key = $this->reference($movement['reference'] ?? null);
            $kind = $movement['kind'] ?? null;
            if (! in_array($kind, ['collection', 'refund'], true)
                || ($movement['status'] ?? null) !== 'posted'
                || ($movement['currency'] ?? null) !== 'SYP'
                || isset($posted[$key])) {
                $this->deny('INVALID_OR_DUPLICATE_POSTED_MOVEMENT');
            }

            $amount = $this->cents($movement['amount'] ?? null);
            if ($amount <= 0) {
                $this->deny('NONPOSITIVE_POSTED_MOVEMENT');
            }
            $posted[$key] = ['kind' => $kind, 'amount' => $amount, 'allocated' => 0, 'index' => $index];
        }

        $seen = [];
        $perVisit = [];
        foreach ($allocations as $allocation) {
            $reference = $this->reference($allocation['reference'] ?? null);
            $visit = $allocation['visitId'] ?? null;
            if (! is_int($visit) || $visit < 1) {
                $this->deny('INVALID_VISIT_ALLOCATION');
            }
            if (! isset($posted[$reference])
                || ($allocation['kind'] ?? null) !== $posted[$reference]['kind']
                || ($allocation['currency'] ?? null) !== 'SYP') {
                $this->deny('UNPOSTED_OR_MISMATCHED_VISIT_ALLOCATION');
            }

            $key = "$reference:$visit";
            if (isset($seen[$key])) {
                $this->deny('DUPLICATE_VISIT_ALLOCATION');
            }
            $seen[$key] = true;
            $amount = $this->cents($allocation['amount'] ?? null);
            if ($amount <= 0) {
                $this->deny('NONPOSITIVE_VISIT_ALLOCATION');
            }

            $posted[$reference]['allocated'] += $amount;
            $perVisit[$visit] ??= ['collected' => 0, 'refunded' => 0];
            $bucket = $posted[$reference]['kind'] === 'refund' ? 'refunded' : 'collected';
            $perVisit[$visit][$bucket] += $amount;
        }

        foreach ($posted as $entry) {
            if ($entry['allocated'] !== $entry['amount']) {
                $this->deny('POSTED_MOVEMENT_ALLOCATION_MISMATCH');
            }
        }

        $totalCollected = 0;
        $totalRefunded = 0;
        ksort($perVisit);
        foreach ($perVisit as $visit => $amounts) {
            if ($amounts['refunded'] > $amounts['collected']) {
                $this->deny('REFUND_EXCEEDS_POSTED_VISIT_COLLECTION');
            }
            $totalCollected += $amounts['collected'];
            $totalRefunded += $amounts['refunded'];
            $perVisit[$visit] = [
                'collected' => $this->format($amounts['collected']),
                'refunded' => $this->format($amounts['refunded']),
                'netCollected' => $this->format($amounts['collected'] - $amounts['refunded']),
            ];
        }

        return [
            'status' => 'conserved',
            'currency' => 'SYP',
            'postedMovementCount' => count($posted),
            'allocatedVisitCount' => count($perVisit),
            'totalCollected' => $this->format($totalCollected),
            'totalRefunded' => $this->format($totalRefunded),
            'netCollected' => $this->format($totalCollected - $totalRefunded),
            'visits' => $perVisit,
        ];
    }

    private function reference(mixed $reference): string
    {
        if (! is_string($reference)
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $reference) !== 1) {
            $this->deny('INVALID_OPERATION_REFERENCE');
        }

        return $reference;
    }

    private function cents(mixed $value): int
    {
        // Financial input is a canonical DECIMAL string only; no floats or
        // scientific notation or binary rounding at the accounting boundary.
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/D', $value)) {
            $this->deny('INVALID_SYP_DECIMAL');
        }
        [$units, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $amount = ((int) $units * 100) + (int) mb_str_pad($fraction, 2, '0');
        if ($amount < 0 || $amount > 99999999999999) {
            $this->deny('SYP_AMOUNT_OVERFLOW');
        }

        return $amount;
    }

    private function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function deny(string $code): never
    {
        throw new DomainException($code);
    }
}

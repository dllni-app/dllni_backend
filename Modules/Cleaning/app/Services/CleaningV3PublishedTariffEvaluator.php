<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Modules\Cleaning\Models\CleaningV3CatalogTariffRule;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Published V3 tariff/effort evaluation. Not an approved financial quote.
 *
 * A published catalog digest and exact rule coverage are mandatory; this
 * class never falls back to an editable draft, a V2 price or customer money.
 * Values are decimal strings evaluated with BCMath and rounded once per
 * line to two places. Worker scheduling and funding remain separate gates.
 */
final class CleaningV3PublishedTariffEvaluator
{
    /**
     * @param  list<array{itemKey:string,cleaningMode:string,scopeKey:string,quantity:string}>  $lines
     * @return array<string,mixed>
     */
    public function evaluate(
        CleaningV3CatalogVersion $catalog,
        string $publishedDigest,
        array $lines,
    ): array {
        if (! extension_loaded('bcmath')) {
            throw new DomainException('V3_EXACT_ARITHMETIC_UNAVAILABLE');
        }
        if ($catalog->status !== CleaningV3CatalogVersion::PUBLISHED
            || ! is_string($catalog->published_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $publishedDigest) !== 1
            || ! hash_equals($catalog->published_digest, $publishedDigest)) {
            throw new DomainException('V3_PUBLISHED_CATALOG_REQUIRED');
        }
        if (! array_is_list($lines) || count($lines) < 1 || count($lines) > 100) {
            throw new DomainException('INVALID_V3_TARIFF_LINES');
        }

        $catalog->loadMissing('items.tariffRules');
        $items = $catalog->items->where('is_enabled', true)->keyBy('item_key');
        $seen = [];
        $priced = [];
        $priceSum = '0.00';
        $effortSum = '0.00';

        foreach ($lines as $line) {
            if (! is_array($line) || array_is_list($line)
                || array_diff(array_keys($line), ['itemKey', 'cleaningMode', 'scopeKey', 'quantity']) !== []
                || ! $this->key($line['itemKey'] ?? null)
                || ! in_array($line['cleaningMode'] ?? null, CleaningV3CatalogTariffRule::MODES, true)
                || ! $this->key($line['scopeKey'] ?? null)
                || ! is_string($line['quantity'] ?? null)
                || preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $line['quantity']) !== 1
                || bccomp($line['quantity'], '0', 2) <= 0) {
                throw new DomainException('INVALID_V3_TARIFF_LINES');
            }

            $identity = $line['itemKey'].'|'.$line['cleaningMode'].'|'.$line['scopeKey'];
            if (isset($seen[$identity])) {
                throw new DomainException('DUPLICATE_V3_TARIFF_SCOPE');
            }
            $seen[$identity] = true;

            $item = $items->get($line['itemKey']);
            if ($item === null || $item->source_type === 'equipment') {
                throw new DomainException('V3_PUBLISHED_TARIFF_ITEM_UNAVAILABLE');
            }
            // A tariff tier can be wider than the form's approved quantity
            // bounds. Direct pricing callers may not bypass those bounds.
            $inputRules = is_array($item->input_rules) ? $item->input_rules : [];
            foreach (['min', 'max'] as $boundName) {
                if (! array_key_exists($boundName, $inputRules)) {
                    continue;
                }
                $bound = $inputRules[$boundName];
                if (! is_string($bound) && ! is_int($bound) && ! is_float($bound)) {
                    throw new DomainException('V3_PUBLISHED_MEASUREMENT_INVALID');
                }
                $decimalBound = (string) $bound;
                if (preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $decimalBound) !== 1) {
                    throw new DomainException('V3_PUBLISHED_MEASUREMENT_INVALID');
                }
                $comparison = bccomp($line['quantity'], $decimalBound, 2);
                if (($boundName === 'min' && $comparison < 0)
                    || ($boundName === 'max' && $comparison > 0)) {
                    throw new DomainException('V3_PUBLISHED_MEASUREMENT_INVALID');
                }
            }
            if (array_key_exists('step', $inputRules)) {
                $stepValue = $inputRules['step'];
                $step = (is_int($stepValue) || is_float($stepValue) || is_string($stepValue))
                    ? (string) $stepValue : '';
                if (preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $step) !== 1
                    || bccomp($step, '0', 2) <= 0) {
                    throw new DomainException('V3_PUBLISHED_MEASUREMENT_INVALID');
                }
                $base = (string) ($inputRules['min'] ?? '0');
                if (bccomp(bcmod(bcsub($line['quantity'], $base, 2), $step, 2), '0', 2) !== 0) {
                    throw new DomainException('V3_PUBLISHED_MEASUREMENT_INVALID');
                }
            }
            // Count and piece are indivisible units. A fixed line is exactly
            // one unit. Do not let a decimal quantity create fractional tasks.
            if ((in_array($item->measurement_mode, ['count', 'piece'], true)
                    && bccomp($line['quantity'], bcadd($line['quantity'], '0', 0), 2) !== 0)
                || ($item->measurement_mode === 'fixed'
                    && bccomp($line['quantity'], '1', 2) !== 0)) {
                throw new DomainException('V3_PUBLISHED_MEASUREMENT_INVALID');
            }

            $matching = $item->tariffRules
                ->where('is_enabled', true)
                ->where('cleaning_mode', $line['cleaningMode'])
                ->where('scope_key', $line['scopeKey'])
                ->filter(static fn ($rule): bool => bccomp($line['quantity'], (string) $rule->quantity_min, 2) >= 0
                    && bccomp($line['quantity'], (string) $rule->quantity_max, 2) < 0);

            if ($matching->count() !== 1) {
                throw new DomainException($matching->isEmpty()
                    ? 'V3_PUBLISHED_TARIFF_COVERAGE_MISSING'
                    : 'V3_PUBLISHED_TARIFF_AMBIGUOUS');
            }

            $rule = $matching->first();
            $price = (string) $rule->unit_price;
            $effort = (string) $rule->unit_effort_minutes;
            if ($rule->currency !== 'SYP'
                || ! $this->positiveDecimal($price, 14)
                || ! $this->positiveDecimal($effort, 3)
                || bccomp($effort, '480', 2) > 0) {
                throw new DomainException('INVALID_V3_PUBLISHED_TARIFF');
            }

            $linePrice = $this->multiplyRound($price, $line['quantity']);
            $lineEffort = $this->multiplyRound($effort, $line['quantity']);
            $priceSum = bcadd($priceSum, $linePrice, 2);
            $effortSum = bcadd($effortSum, $lineEffort, 2);

            if (bccomp($priceSum, '999999999999.99', 2) > 0
                || bccomp($effortSum, '999999999.99', 2) > 0) {
                throw new DomainException('V3_PUBLISHED_TARIFF_TOTAL_OVERFLOW');
            }

            $priced[] = [
                'itemKey' => $item->item_key,
                'cleaningMode' => $line['cleaningMode'],
                'scopeKey' => $line['scopeKey'],
                'ruleKey' => (string) $rule->rule_key,
                'measurementMode' => (string) $item->measurement_mode,
                'quantity' => bcadd($line['quantity'], '0', 2),
                'unitPriceSyp' => bcadd($price, '0', 2),
                'unitEffortMinutes' => bcadd($effort, '0', 2),
                'lineAmountSyp' => $linePrice,
                'lineEffortMinutes' => $lineEffort,
            ];
        }

        return [
            'catalogVersionKey' => (string) $catalog->version_key,
            'catalogRevision' => $publishedDigest,
            'currency' => 'SYP',
            'lines' => $priced,
            'estimatedBaseAmountSyp' => $priceSum,
            'estimatedEffortMinutes' => $effortSum,
            'requiresCapacityApproval' => true,
            'requiresFundingAdmission' => true,
            'canQuote' => false,
            'canBook' => false,
            'canSettle' => false,
        ];
    }

    private function key(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $value) === 1;
    }

    private function positiveDecimal(string $value, int $wholeDigits): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,'.($wholeDigits - 1).'})(?:\.[0-9]{1,2})?$/D', $value) === 1
            && bccomp($value, '0', 2) > 0;
    }

    private function multiplyRound(string $unit, string $quantity): string
    {
        return bcdiv(bcadd(bcmul($unit, $quantity, 4), '0.0050', 4), '1', 2);
    }
}

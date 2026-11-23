<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Modules\Cleaning\Models\CleaningV3CatalogItem;
use Modules\Cleaning\Models\CleaningV3CatalogTariffRule;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Admin-only draft illustration of tariff and effort calculations.
 *
 * This service does not issue a signed quote, reserve funding, select workers,
 * create a booking, or authorize any settlement.
 *
 * Amounts are SYP decimal strings; never use binary floating-point math.
 * Every quantity tier is [min, max), with max exclusive.
 */
final class CleaningV3CatalogDraftEstimateService
{
    public function __construct(private readonly CleaningV3CatalogDraftValidator $validator) {}

    /**
     * @param  list<array{itemKey:string,cleaningMode:string,scopeKey:string,quantity:string}>  $inputs
     * @return array<string,mixed>
     */
    public function estimate(CleaningV3CatalogVersion $version, array $inputs): array
    {
        if (! extension_loaded('bcmath')) {
            throw new DomainException('V3_EXACT_ARITHMETIC_UNAVAILABLE');
        }
        if ($version->status !== CleaningV3CatalogVersion::DRAFT) {
            throw new DomainException('V3_DRAFT_REQUIRED');
        }
        if ($inputs === [] || count($inputs) > 100) {
            throw new DomainException('V3_DRAFT_PREVIEW_INPUTS_INVALID');
        }

        $seen = [];
        $totalAmount = '0.00';
        $totalEffort = '0.00';
        $items = [];

        foreach ($inputs as $input) {
            $key = $input['itemKey'] ?? null;
            $mode = $input['cleaningMode'] ?? null;
            $scope = $input['scopeKey'] ?? null;
            $quantity = $input['quantity'] ?? null;

            if (! is_string($key) || ! is_string($mode)
                || ! is_string($scope) || ! is_string($quantity)
                || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $key)
                || ! in_array($mode, CleaningV3CatalogTariffRule::MODES, true)
                || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $scope)
                || ! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $quantity)
                || bccomp($quantity, '0', 2) <= 0) {
                throw new DomainException('V3_DRAFT_PREVIEW_INPUTS_INVALID');
            }

            $identity = $key.'|'.$mode.'|'.$scope;
            if (isset($seen[$identity])) {
                throw new DomainException('V3_DRAFT_PREVIEW_DUPLICATE_SCOPE');
            }
            $seen[$identity] = true;

            $item = $version->items()->where('item_key', $key)
                ->where('is_enabled', true)->first();
            if (! $item instanceof CleaningV3CatalogItem) {
                throw new DomainException('V3_DRAFT_ITEM_UNAVAILABLE');
            }
            $this->validator->assertActiveSource(
                (string) $item->source_type, (int) $item->source_id,
            );

            $rule = $this->resolveExactRule($item, $mode, $scope, $quantity);
            $unitPrice = (string) $rule->unit_price;
            $unitEffort = (string) $rule->unit_effort_minutes;
            if (! $this->validPositive($unitPrice) || ! $this->validPositive($unitEffort)
                || bccomp($unitEffort, '480', 2) > 0 || $rule->currency !== 'SYP') {
                throw new DomainException('V3_DRAFT_TARIFF_INVALID');
            }

            // With two decimal inputs, a multiplication has up to four
            // decimal places. Round half-up once per line, never per unit.
            $amount = $this->multiplyRounded($unitPrice, $quantity);
            $effortMinutes = $this->multiplyRounded($unitEffort, $quantity);
            $totalAmount = bcadd($totalAmount, $amount, 2);
            $totalEffort = bcadd($totalEffort, $effortMinutes, 2);

            if (bccomp($totalAmount, '99999999999999.99', 2) > 0
                || bccomp($totalEffort, '999999999.99', 2) > 0) {
                throw new DomainException('V3_DRAFT_PREVIEW_CAPACITY_EXCEEDED');
            }

            $items[] = [
                'itemKey' => $key,
                'cleaningMode' => $mode,
                'scopeKey' => $scope,
                'ruleKey' => (string) $rule->rule_key,
                'quantity' => bcadd($quantity, '0', 2),
                'unitPriceSyp' => bcadd($unitPrice, '0', 2),
                'unitEffortMinutes' => bcadd($unitEffort, '0', 2),
                'estimatedAmountSyp' => $amount,
                'estimatedEffortMinutes' => $effortMinutes,
            ];
        }

        return [
            'catalogVersionKey' => (string) $version->version_key,
            'currency' => 'SYP',
            'items' => $items,
            'estimatedBaseAmountSyp' => $totalAmount,
            'estimatedEffortMinutes' => $totalEffort,
            'requiresCapacityApproval' => true,
            'canQuote' => false,
            'canBook' => false,
            'canSettle' => false,
        ];
    }

    private function resolveExactRule(
        CleaningV3CatalogItem $item,
        string $mode,
        string $scope,
        string $quantity,
    ): CleaningV3CatalogTariffRule {
        $rules = $item->tariffRules()
            ->where('cleaning_mode', $mode)
            ->where('scope_key', $scope)
            ->where('is_enabled', true)
            ->get();

        $matching = $rules->filter(
            static fn (CleaningV3CatalogTariffRule $rule): bool => bccomp($quantity, (string) $rule->quantity_min, 2) >= 0
                && bccomp($quantity, (string) $rule->quantity_max, 2) < 0,
        );

        if ($matching->count() === 0) {
            throw new DomainException('V3_DRAFT_TARIFF_COVERAGE_MISSING');
        }
        if ($matching->count() !== 1) {
            throw new DomainException('V3_DRAFT_TARIFF_AMBIGUOUS');
        }

        return $matching->first();
    }

    private function validPositive(string $amount): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/D', $amount) === 1
            && bccomp($amount, '0', 2) > 0;
    }

    private function multiplyRounded(string $value, string $quantity): string
    {
        $product = bcmul($value, $quantity, 4);

        return bcdiv(bcadd($product, '0.0050', 4), '1', 2);
    }
}

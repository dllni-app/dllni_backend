<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Modules\Cleaning\Models\CleaningV3CatalogItem;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Timed event tariffs use one worker-hour as the unit. Select a single
 * regular/whole tier for the entire canonical event, not for each visit.
 */
final class CleaningV3EventTariffPricing
{
    public function __construct(private readonly CleaningV3PublishedTariffEvaluator $tariffs) {}

    /** @return array{ruleKey:string, quantity:string, unitPriceSyp:string, totalAmountSyp:string, visits:array<string,string>} */
    public function price(CleaningV3CatalogVersion $catalog, CleaningV3CatalogItem $item, array $plan, array $event): array
    {
        if ($item->source_type !== 'event_type' || $item->measurement_mode !== 'hour'
            || ! $item->is_enabled || $item->item_key !== ($event['eventItemKey'] ?? null)
            || count($plan['appointments'] ?? []) !== 1) {
            throw new DomainException('V3_COMMERCIAL_EVENT_TARIFF_UNSUPPORTED');
        }
        // An hourly event has an exact scheduled workload: 60 active minutes
        // per worker-hour. A conflicting effort definition cannot be ignored.
        $enabled = $item->tariffRules->where('is_enabled', true);
        if ($enabled->isEmpty()) {
            throw new DomainException('V3_PUBLISHED_TARIFF_COVERAGE_MISSING');
        }
        foreach ($enabled as $rule) {
            if ($rule->cleaning_mode !== 'regular' || $rule->scope_key !== 'whole'
                || bccomp((string) $rule->unit_effort_minutes, '60', 2) !== 0) {
                throw new DomainException('V3_COMMERCIAL_EVENT_TARIFF_UNSUPPORTED');
            }
        }
        $quantities = [];
        $totalMinutes = 0;
        $workerMinutes = 0;
        $crew = null;
        foreach ($plan['appointments'][0]['visits'] as $visit) {
            $key = $visit['occurrenceKey'];
            $minutes = $visit['durationMinutes'];
            $workers = $visit['requiredWorkers'];
            if (isset($quantities[$key]) || ! is_int($minutes) || $minutes < 60 || $minutes > 480
                || $minutes % 30 !== 0 || ! is_int($workers) || $workers < 1 || $workers > 20
                || ($crew !== null && $workers !== $crew)) {
                throw new DomainException('V3_EVENT_PLAN_MISMATCH');
            }
            $crew = $workers;
            $totalMinutes += $minutes;
            $workerMinutes += $minutes * $workers;
            $quantities[$key] = bcdiv((string) ($minutes * $workers), '60', 2);
        }
        if ($totalMinutes !== ($event['durationMinutes'] ?? null) || $quantities === []) {
            throw new DomainException('V3_EVENT_PLAN_MISMATCH');
        }
        $quantity = bcdiv((string) $workerMinutes, '60', 2);
        $priced = $this->tariffs->evaluate($catalog, $plan['catalogRevision'], [[
            'itemKey' => $item->item_key, 'cleaningMode' => 'regular', 'scopeKey' => 'whole',
            'quantity' => $quantity,
        ]]);
        $line = $priced['lines'][0];
        if (bccomp($line['lineEffortMinutes'], (string) $workerMinutes, 2) !== 0) {
            throw new DomainException('V3_COMMERCIAL_EVENT_TARIFF_UNSUPPORTED');
        }

        // Difference of rounded cumulative amounts conserves the exact full
        // event tariff even at half-hour boundaries with fractional prices.
        $cumulativeQuantity = '0.00';
        $cumulativeAmount = '0.00';
        $visits = [];
        foreach ($quantities as $key => $visitQuantity) {
            $cumulativeQuantity = bcadd($cumulativeQuantity, $visitQuantity, 2);
            $nextAmount = bcdiv(bcadd(bcmul($line['unitPriceSyp'], $cumulativeQuantity, 4), '0.0050', 4), '1', 2);
            $visits[$key] = bcsub($nextAmount, $cumulativeAmount, 2);
            $cumulativeAmount = $nextAmount;
        }
        if (bccomp($cumulativeAmount, $line['lineAmountSyp'], 2) !== 0) {
            throw new DomainException('V3_TASK_PRICE_CONSERVATION_FAILED');
        }
        return [
            'ruleKey' => $line['ruleKey'], 'quantity' => $quantity,
            'unitPriceSyp' => $line['unitPriceSyp'], 'totalAmountSyp' => $cumulativeAmount,
            'visits' => $visits,
        ];
    }
}

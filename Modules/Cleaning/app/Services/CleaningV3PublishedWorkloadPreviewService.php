<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Server-catalog-only, read-only V3 capacity preview.
 *
 * Each count/piece is an independent non-splittable task. Square metres,
 * metres, hourly and fixed units are atomic lines unless a separately
 * approved decomposition rule is provided. Unsupported long jobs fail
 * closed rather than being silently split into unpriced visits.
 */
final class CleaningV3PublishedWorkloadPreviewService
{
    public function __construct(
        private readonly CleaningV3PublishedTariffEvaluator $tariffs,
        private readonly CleaningV3WorkloadCapacityPlanner $capacity,
        private readonly CleaningV3CatalogPublicSerializer $serializer,
        private readonly CleaningV3PublishedFormValidator $forms,
    ) {}

    /**
     * @param  list<array{itemKey:string,cleaningMode:string,scopeKey:string,quantity:string}>  $lines
     * @return array<string,mixed>
     */
    public function preview(
        CleaningV3CatalogVersion $catalog,
        string $publishedDigest,
        array $lines,
        int $maximumWorkers = 20,
        int $windowMinutes = 480,
    ): array {
        $priced = $this->tariffs->evaluate($catalog, $publishedDigest, $lines);
        $tasks = [];
        foreach ($priced['lines'] as $row) {
            $taskPrefix = $row['itemKey'].':'.$row['cleaningMode'].':'.$row['scopeKey'];
            if (in_array($row['measurementMode'], ['count', 'piece'], true)) {
                $units = (int) $row['quantity'];
                if ($units > 40) {
                    throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
                }
                $unitMinutes = $this->roundMinutes($row['unitEffortMinutes']);
                for ($i = 1; $i <= $units; $i++) {
                    $tasks[] = [
                        'taskKey' => $taskPrefix.':'.$i,
                        'durationMinutes' => $unitMinutes,
                        'splittable' => false,
                    ];
                }
            } else {
                $tasks[] = [
                    'taskKey' => $taskPrefix.':line',
                    'durationMinutes' => $this->roundMinutes($row['lineEffortMinutes']),
                    'splittable' => false,
                ];
            }

            if (count($tasks) > 40) {
                throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
            }
        }

        $result = $this->capacity->estimate(
            $tasks,
            $maximumWorkers,
            $windowMinutes,
        );

        return [
            'catalogVersionKey' => $priced['catalogVersionKey'],
            'catalogRevision' => $priced['catalogRevision'],
            'currency' => 'SYP',
            'estimatedBaseAmountSyp' => $priced['estimatedBaseAmountSyp'],
            'estimatedEffortMinutes' => $priced['estimatedEffortMinutes'],
            'capacity' => $result,
            'requiresActualWorkerConflictCheck' => true,
            'requiresGeographicCheck' => true,
            'requiresApprovedCommercialPricing' => true,
            'canQuote' => false,
            'canBook' => false,
            'canSettle' => false,
        ];
    }

    /**
     * Price the actual validated draft, not a client-selected subset of its
     * payable fields. Only whole-scope numeric tariffs are supported here.
     * Unsupported advanced priced controls must not produce a partial total.
     */
    public function previewForm(
        CleaningV3CatalogVersion $catalog,
        string $digest,
        array $answers,
        mixed $mode,
    ): array {
        if (! in_array($mode, ['regular', 'deep'], true)) {
            throw new \DomainException('V3_CLEANING_MODE_REQUIRED');
        }
        $fields = $this->serializer->serialize($catalog, 'ar')['items'];
        $normalized = $this->forms->normalize($fields, $answers);
        $items = $catalog->items->keyBy('item_key');
        $lines = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $normalized)) {
                continue;
            }
            $item = $items->get($key);
            if ($item === null) {
                throw new \DomainException('V3_PUBLISHED_TARIFF_ITEM_UNAVAILABLE');
            }
            // Non-payable metadata is validated above, not included in costs.
            if ($item->tariffRules->where('is_enabled', true)->isEmpty()) {
                if (in_array($field['componentId'], ['numeric', 'stepper'], true)) {
                    throw new \DomainException('V3_PUBLISHED_TARIFF_COVERAGE_MISSING');
                }
                continue;
            }
            if (! in_array($field['componentId'], ['numeric', 'stepper'], true)
                || ! in_array($mode, $field['wholePreviewModes'], true)) {
                throw new \DomainException('V3_FORM_PRICING_RULE_UNSUPPORTED');
            }
            $quantity = (string) $normalized[$key];
            $lines[] = ['itemKey' => $key, 'cleaningMode' => $mode,
                'scopeKey' => 'whole', 'quantity' => $quantity];
        }
        if ($lines === []) {
            throw new \DomainException('V3_FORM_HAS_NO_PRICED_ITEMS');
        }

        $result = $this->preview($catalog, $digest, $lines);

        return [
            ...$result,
            'cleaningMode' => $mode,
            'pricedItemKeys' => array_column($lines, 'itemKey'),
            'formFingerprint' => hash('sha256', json_encode([
                'catalogVersionKey' => $catalog->version_key,
                'catalogRevision' => $digest,
                'cleaningMode' => $mode,
                'answers' => $normalized,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function roundMinutes(string $decimal): int
    {
        if (! extension_loaded('bcmath')
            || bccomp($decimal, '0', 2) <= 0
            || bccomp($decimal, '480', 2) > 0) {
            throw new CleaningV3CapacityException('CAPACITY_INFEASIBLE');
        }

        $rounded = bcadd($decimal, '0', 0);

        return (int) (bccomp($decimal, $rounded, 2) > 0
            ? bcadd($rounded, '1', 0)
            : $rounded);
    }
}

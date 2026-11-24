<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Modules\Cleaning\Models\CleaningV3CatalogItem;

/** No continuous-measurement task is split unless a published quantum explicitly permits it. */
final class CleaningV3TaskSplitPolicy
{
    public function valid(CleaningV3CatalogItem $item): bool
    {
        $rules = $item->input_rules ?? [];
        if (! array_key_exists('splitUnitQuantity', $rules)) {
            return true;
        }
        $value = $rules['splitUnitQuantity'];
        return in_array($item->measurement_mode, ['sqm', 'linear_meter', 'hour'], true)
            && $item->source_type !== 'equipment'
            && (is_string($value) || is_int($value) || is_float($value))
            && preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', (string) $value) === 1
            && (float) $value > 0;
    }

    public function quantities(CleaningV3CatalogItem $item, string $total): array
    {
        if (! $this->valid($item)) {
            throw new DomainException('V3_TASK_SPLIT_POLICY_INVALID');
        }
        $quantum = $item->input_rules['splitUnitQuantity'] ?? null;
        if ($quantum === null) {
            return [$total];
        }
        $remaining = $total;
        $chunks = [];
        while (bccomp($remaining, '0', 2) > 0) {
            if (count($chunks) >= 40) {
                throw new CleaningV3CapacityException('CAPACITY_CHECK_UNAVAILABLE', 503);
            }
            $chunk = bccomp($remaining, (string) $quantum, 2) < 0 ? $remaining : bcadd((string) $quantum, '0', 2);
            $chunks[] = $chunk;
            $remaining = bcsub($remaining, $chunk, 2);
        }
        return $chunks;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningV3CatalogItem;

/**
 * Reusable platform-supplied equipment is a separate priced rental line,
 * not a cleaning task or a consumable. Catalog pools refer to existing assets.
 * Availability is a read-only projection; booking must repeat under asset locks.
 */
final class CleaningV3EquipmentPreviewService
{
    /** @return array{assetIds:list<int>,unitPriceSyp:string}|null */
    public function configuration(CleaningV3CatalogItem $item): ?array
    {
        $rules = $item->input_rules ?? [];
        $extra = $rules['equipmentAssetIds'] ?? [];
        $price = $rules['equipmentUnitPriceSyp'] ?? null;
        if ($item->source_type !== 'equipment' || $item->measurement_mode !== 'piece'
            || ($rules['componentId'] ?? 'stepper') !== 'stepper'
            || ! is_array($extra) || ! array_is_list($extra) || count($extra) > 19
            || (! is_string($price) && ! is_int($price) && ! is_float($price))
            || preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', (string) $price) !== 1
            || ! extension_loaded('bcmath') || bccomp((string) $price, '0', 2) <= 0
            || array_intersect(array_keys($rules), ['min', 'max', 'step', 'scopeLabels', 'maxSelections']) !== []
            || $item->tariffRules->where('is_enabled', true)->isNotEmpty()) {
            return null;
        }
        $ids = [(int) $item->source_id];
        foreach ($extra as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id)
                || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                return null;
            }
            $ids[] = (int) $id;
        }
        if ($ids[0] < 1 || count(array_unique($ids)) !== count($ids)) {
            return null;
        }
        sort($ids, SORT_NUMERIC);

        return ['assetIds' => $ids, 'unitPriceSyp' => bcadd((string) $price, '0', 2)];
    }

    /** Validate selected pools and price only from the immutable catalog. */
    public function requests(iterable $items, array $answers): array
    {
        $requests = [];
        $seen = [];
        foreach ($items as $item) {
            if ($item->source_type !== 'equipment' || ! array_key_exists($item->item_key, $answers)) {
                continue;
            }
            $quantity = $answers[$item->item_key];
            if ($quantity === 0 && ! $item->required) {
                continue;
            }
            $config = $this->configuration($item);
            if ($config === null || ! is_int($quantity) || $quantity < 1 || $quantity > count($config['assetIds'])) {
                throw new DomainException('V3_EQUIPMENT_CONFIGURATION_INVALID');
            }
            foreach ($config['assetIds'] as $id) {
                if (isset($seen[$id])) {
                    throw new DomainException('V3_EQUIPMENT_POOL_OVERLAP');
                }
                $seen[$id] = true;
            }
            $requests[] = [
                'itemKey' => $item->item_key,
                'label' => $item->label_ar,
                'quantity' => $quantity,
                ...$config,
                'estimatedAmountSyp' => bcmul($config['unitPriceSyp'], (string) $quantity, 2),
            ];
        }
        return $requests;
    }

    /** Return no asset IDs or booking/custody details to the customer. */
    public function unscheduled(array $requests): array
    {
        $total = '0.00';
        $rows = [];
        foreach ($requests as $request) {
            $total = bcadd($total, $request['estimatedAmountSyp'], 2);
            $available = CleaningSpecialServiceEquipment::query()
                ->whereIn('id', $request['assetIds'])->where('is_active', true)
                ->where('status', 'available')
                ->whereNotIn('id', CleaningEquipmentReservation::query()
                    ->select('cleaning_special_service_equipment_id')
                    ->where(function ($query): void {
                        $query->whereIn('status', ['handed_over', 'acknowledged', 'return_pending_confirmation', 'failure_pending_confirmation'])
                            ->orWhere(function ($reserved): void {
                                $reserved->where('status', 'reserved')->where('reserved_from', '<=', now())
                                    ->where('reserved_until', '>', now());
                            });
                    }))->count();
            $rows[] = [...$this->publicLine($request), 'currentlyAvailableUnits' => $available];
        }
        return [
            'currency' => 'SYP', 'previewOnly' => true, 'canReserve' => false,
            'requiresScheduleCheck' => true, 'estimatedEquipmentAmountPerVisitSyp' => $total,
            'items' => $rows,
        ];
    }

    /**
     * Recompute all buffered visit windows together. Assets may be reused only
     * when intervals do not overlap; unconfirmed custody blocks the asset.
     * The lock option is for a future transactional confirmed-booking writer,
     * NOT a reservation, charge, or permission to create a booking.
     */
    public function allocate(array $requests, array $appointments, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('V3_EQUIPMENT_TRANSACTION_REQUIRED');
        }
        if ($requests === []) {
            return [];
        }
        $visits = [];
        foreach ($appointments as $appointment) {
            foreach ($appointment['visits'] as $visit) {
                $visits[] = $visit;
            }
        }
        usort($visits, static fn (array $a, array $b) => [$a['startAt'], $a['occurrenceKey']] <=> [$b['startAt'], $b['occurrenceKey']]);
        $ids = array_values(array_unique(array_merge([], ...array_column($requests, 'assetIds'))));
        sort($ids, SORT_NUMERIC);
        $assetsQuery = CleaningSpecialServiceEquipment::query()->whereIn('id', $ids)->orderBy('id');
        $assets = ($lock ? $assetsQuery->lockForUpdate() : $assetsQuery)->get()->keyBy('id');
        $reservationsQuery = CleaningEquipmentReservation::query()->whereIn('cleaning_special_service_equipment_id', $ids)
            ->whereIn('status', ['reserved', 'handed_over', 'acknowledged', 'return_pending_confirmation', 'failure_pending_confirmation'])
            ->orderBy('cleaning_special_service_equipment_id')->orderBy('id');
        $reservations = ($lock ? $reservationsQuery->lockForUpdate() : $reservationsQuery)->get()->groupBy('cleaning_special_service_equipment_id');
        $result = [];
        $budget = 100000;
        foreach ($requests as $request) {
            $demands = [];
            foreach ($visits as $visit) {
                $candidates = [];
                $start = (new DateTimeImmutable($visit['startAt']))->getTimestamp();
                $end = (new DateTimeImmutable($visit['endAt']))->getTimestamp();
                foreach ($request['assetIds'] as $id) {
                    $asset = $assets->get($id);
                    if ($asset === null || ! $asset->is_active || $asset->status !== 'available'
                        || $asset->buffer_before_minutes < 0 || $asset->buffer_after_minutes < 0) {
                        continue;
                    }
                    $from = $start - $asset->buffer_before_minutes * 60;
                    $until = $end + $asset->buffer_after_minutes * 60;
                    $blocked = false;
                    foreach ($reservations->get($id, []) as $reservation) {
                        if ($reservation->status !== 'reserved' || $reservation->reserved_from === null
                            || $reservation->reserved_until === null
                            || $reservation->reserved_until->lte($reservation->reserved_from)
                            || ($reservation->reserved_from->getTimestamp() < $until
                                && $reservation->reserved_until->getTimestamp() > $from)) {
                            $blocked = true;
                            break;
                        }
                    }
                    if (! $blocked) {
                        $candidates[$id] = [$from, $until];
                    }
                }
                if (count($candidates) < $request['quantity']) {
                    throw new CleaningV3VisitPlanException('equipment.'.$request['itemKey'], 'V3_EQUIPMENT_UNAVAILABLE');
                }
                for ($i = 0; $i < $request['quantity']; $i++) {
                    $demands[] = ['visitKey' => $visit['occurrenceKey'], 'candidates' => $candidates];
                }
            }
            // Bounded exact backtracking rather than falsely claiming stock
            // from the sum of pools; sort by most constrained demand first.
            usort($demands, static fn ($a, $b) => count($a['candidates']) <=> count($b['candidates']));
            $used = [];
            $selected = [];
            if (! $this->assign($demands, 0, $used, $selected, $budget)) {
                throw new CleaningV3VisitPlanException('equipment.'.$request['itemKey'], 'V3_EQUIPMENT_UNAVAILABLE');
            }
            foreach ($selected as $entry) {
                $result[$entry['visitKey']][$request['itemKey']][] = $entry;
            }
        }
        return $result;
    }

    private function assign(array $demands, int $index, array &$used, array &$selected, int &$budget): bool
    {
        if (--$budget < 0) {
            throw new CleaningV3CapacityException('V3_EQUIPMENT_CHECK_UNAVAILABLE', 503, 'equipment');
        }
        if ($index === count($demands)) {
            return true;
        }
        $demand = $demands[$index];
        foreach ($demand['candidates'] as $id => [$from, $until]) {
            $overlap = false;
            foreach ($used[$id] ?? [] as [$otherFrom, $otherUntil]) {
                if ($otherFrom < $until && $otherUntil > $from) {
                    $overlap = true;
                    break;
                }
            }
            if ($overlap) {
                continue;
            }
            $used[$id][] = [$from, $until];
            $selected[] = ['visitKey' => $demand['visitKey'], 'assetId' => $id, 'from' => $from, 'until' => $until];
            if ($this->assign($demands, $index + 1, $used, $selected, $budget)) {
                return true;
            }
            array_pop($used[$id]);
            array_pop($selected);
        }
        return false;
    }

    public function scheduled(array $requests, array $appointments): array
    {
        $this->allocate($requests, $appointments);
        $total = '0.00';
        $visits = [];
        foreach ($appointments as $appointment) {
            foreach ($appointment['visits'] as $visit) {
                $amount = '0.00';
                foreach ($requests as $request) {
                    $amount = bcadd($amount, $request['estimatedAmountSyp'], 2);
                }
                $total = bcadd($total, $amount, 2);
                $visits[] = [
                    'occurrenceKey' => $visit['occurrenceKey'],
                    'estimatedEquipmentAmountSyp' => $amount,
                    'items' => array_map($this->publicLine(...), $requests),
                ];
            }
        }
        return [
            'currency' => 'SYP', 'previewOnly' => true, 'canReserve' => false,
            'availabilityChecked' => true, 'requiresLockedRecheck' => true,
            'totalEstimatedEquipmentAmountSyp' => $total, 'visits' => $visits,
        ];
    }

    private function publicLine(array $request): array
    {
        return array_intersect_key($request, array_flip(['itemKey', 'label', 'quantity', 'unitPriceSyp', 'estimatedAmountSyp']));
    }
}

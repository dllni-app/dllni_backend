<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Services\CleaningMaterialQuoteService;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\Cleaning\Services\CleaningSpecialServiceQuoteService;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Support\CleaningRuntimeSettings;
use Modules\Cleaning\Services\CleaningSupervisorHourlyPricing;

/**
 * Deterministic period-level quote shared by estimate and create.
 *
 * Bookings contain per-visit rooms; transport/admin margin/minimum apply ONCE
 * to the aggregate rather than once for every selected room.
 */
final class RoomPeriodPricingService
{
    public function __construct(
        private readonly UserCleaningOrderEstimationService $estimator,
        private readonly CleaningPricingCalculator $calculator,
        private readonly CleaningMaterialQuoteService $materials,
        private readonly CleaningSpecialServiceQuoteService $services,
    ) {}

    /** @param array<string,mixed> $validated */
    public function quote(array $validated): array
    {
        $periods = $validated['roomPeriods'] ?? [];
        if (! is_array($periods) || $periods === []) {
            throw ValidationException::withMessages(['roomPeriods' => ['At least one room period is required.']]);
        }

        $rows = [];
        $base = 0.0;
        $specials = 0.0;
        $materialKitMaxByVisit = [];
        $events = [];
        $serviceIds = [];
        foreach ($periods as $periodInput) {
            foreach ((array) ($periodInput['specialServices'] ?? []) as $requested) {
                $serviceIds[] = (int) ($requested['specialServiceId'] ?? 0);
            }
        }
        $minutesPerService = CleaningSpecialService::query()
            ->whereIn('id', array_values(array_unique($serviceIds)))
            ->pluck('estimated_duration_minutes', 'id');

        $firstStart = null;
        $latestEnd = null;
        $workMinutes = 0;
        $supervisorIntervals = [];

        foreach ($periods as $index => $period) {
            $type = (string) $period['roomType'];
            $size = (string) $period['size'];
            $mode = (string) $period['cleaningMode'];
            $workers = (int) $period['numberOfWorkers'];
            $visit = (int) $period['visitSequence'];

            $singleRoomDetails = [
                'cleaning_mode' => $mode,
                'room_size_breakdown' => [
                    $type => [$size => 1],
                ],
            ];
            if (isset($period['estimatedHours'])) {
                $singleRoomDetails['room_estimated_hours'] = [$type.'.'.$size.'.1' => $period['estimatedHours']];
            }
            // Use the production room pricing algorithm. Read its raw room
            // line, NEVER its booking-level minimum or individual travel fee.
            $singleQuote = $this->estimator->price('apartment', $singleRoomDetails, null, null, null, null, false, [], $workers);
            $line = (array) ($singleQuote['roomPricingLines'][0] ?? []);
            if ($line === []) {
                throw ValidationException::withMessages(["roomPeriods.$index" => ['Room does not have an available price.']]);
            }
            $roomBase = (float) ($line['totalPrice'] ?? 0);
            $minutes = (int) ($line['totalMinutes'] ?? 0);
            $requestedSpecials = (array) ($period['specialServices'] ?? []);
            foreach ($requestedSpecials as $requested) {
                $serviceMinutes = max(0, (int) ($minutesPerService[(int) ($requested['specialServiceId'] ?? 0)] ?? 0));
                $quantity = max(1, (float) ($requested['quantity'] ?? 1));
                $minutes += (int) ceil($serviceMinutes * $quantity);
            }
            $duration = max(1, (int) ceil($minutes / $workers));

            if ($duration > 480) {
                throw ValidationException::withMessages([
                    "roomPeriods.$index.numberOfWorkers" => [
                        'This room period exceeds eight working hours per worker; increase the team size.'
                    ],
                ]);
            }

            $materialOptions = (array) ($period['materials'] ?? []);
            $selectedMaterialIds = array_values(array_unique(array_map('intval', (array) ($materialOptions['materialTypeIds'] ?? []))));
            $shouldQuoteMaterials = (bool) ($materialOptions['providedByPlatform'] ?? false) || $selectedMaterialIds !== [];
            $materialQuote = $shouldQuoteMaterials
                ? $this->materials->quote(
                    $singleRoomDetails,
                    $selectedMaterialIds === [] ? null : $selectedMaterialIds,
                )
                : ['lines' => [], 'total' => 0.0];
            foreach ($materialQuote['lines'] as $materialLine) {
                // One shared material package per type and visit at the same
                // address, rather than multiplying full packages by room count.
                $kitKey = $visit.':'.(int) $materialLine['materialTypeId'];
                $cost = (float) ($materialLine['totalPrice'] ?? 0.0);
                $materialKitMaxByVisit[$kitKey] = max((float) ($materialKitMaxByVisit[$kitKey] ?? 0), $cost);
            }
            $specialQuote = (array) ($period['specialServices'] ?? []) === []
                ? ['lines' => [], 'total' => 0.0]
                : $this->services->quote((array) $period['specialServices']);

            $start = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i',
                $period['scheduledDate'].' '.$period['scheduledTime'],
                config('app.timezone', 'UTC'),
            );
            if (! $start instanceof CarbonImmutable) {
                throw new InvalidArgumentException('Invalid room-period date or time.');
            }
            $end = $start->addMinutes($duration);
            $supervisorIntervals[] = ['startAt' => $start->toIso8601String(), 'endAt' => $end->toIso8601String()];
            $firstStart = $firstStart === null || $start->lessThan($firstStart) ? $start : $firstStart;
            $latestEnd = $latestEnd === null || $end->greaterThan($latestEnd) ? $end : $latestEnd;
            $events[] = ['at' => $start->getTimestamp(), 'delta' => $workers];
            $events[] = ['at' => $end->getTimestamp(), 'delta' => -$workers];

            $base += $roomBase;
            $specials += (float) $specialQuote['total'];
            $workMinutes += $minutes;
            $rows[] = [
                'clientKey' => (string) $period['clientKey'],
                'visitSequence' => $visit,
                'roomType' => $type,
                'size' => $size,
                'cleaningMode' => $mode,
                'scheduledDate' => (string) $period['scheduledDate'],
                'scheduledTime' => (string) $period['scheduledTime'],
                'estimatedEndAt' => $end->format('Y-m-d H:i'),
                'numberOfWorkers' => $workers,
                'estimatedWorkMinutes' => $minutes,
                'estimatedDurationMinutes' => $duration,
                'basePrice' => $roomBase,
                'specialServicesTotal' => (float) $specialQuote['total'],
                'specialServices' => $specialQuote['lines'],
                'materials' => $materialQuote['lines'],
            ];
        }

        usort($events, static fn (array $a, array $b): int => $a['at'] <=> $b['at'] ?: $a['delta'] <=> $b['delta']);
        $currentWorkers = 0;
        $maximumWorkers = 0;
        foreach ($events as $event) {
            $currentWorkers += $event['delta'];
            $maximumWorkers = max($maximumWorkers, $currentWorkers);
        }
        if ($maximumWorkers > 20) {
            throw ValidationException::withMessages(['roomPeriods' => ['Overlapping periods require more than the allowed 20 simultaneous workers.']]);
        }

        $materialsTotal = round(array_sum($materialKitMaxByVisit), 2);
        $addons = round($materialsTotal + $specials, 2);
        $base = $this->calculator->roundMoney($base);
        $supervisor = null;
        if ($maximumWorkers > 1) {
            $supervisor = app(CleaningSupervisorHourlyPricing::class)->forMinutes(
                app(CleaningSupervisorHourlyPricing::class)->unionMinutes($supervisorIntervals),
            );
            $base = $this->calculator->roundMoney($base + $supervisor['fee']);
        }
        $priced = $this->calculator->provisional($base, $addons, 0.0, max(1, $maximumWorkers));
        $minimum = max(0.0, (float) (CleaningRuntimeSettings::financial()->cleaning_minimum_order_price ?? 0));
        if ($minimum > 0 && (float) $priced['totalPrice'] < $minimum) {
            $base = $this->calculator->roundMoney(max($base, $minimum - (float) $priced['travelFee'] - $addons));
            $priced = $this->calculator->provisional($base, $addons, $base + $addons, max(1, $maximumWorkers));
        }

        return [
            'algorithmVersion' => 'room-period-v2-2026-10-14',
            'roomPeriods' => $rows,
            'basePrice' => $base,
            'supervisor' => $supervisor,
            'addonsTotal' => $addons,
            'materialsTotal' => $materialsTotal,
            'specialServicesTotal' => round($specials, 2),
            'travelFee' => (float) $priced['travelFee'],
            'adminMargin' => (float) $priced['adminMargin'],
            'totalPrice' => (float) $priced['totalPrice'],
            'isPricingFinal' => false,
            'currency' => (string) config('app.currency', 'SYP'),
            'requiredSimultaneousWorkers' => max(1, $maximumWorkers),
            'estimatedWorkMinutes' => $workMinutes,
            'estimatedDurationMinutes' => $firstStart->diffInMinutes($latestEnd),
            'earliestStart' => $firstStart->format('Y-m-d H:i'),
            'latestEnd' => $latestEnd->format('Y-m-d H:i'),
        ];
    }
}

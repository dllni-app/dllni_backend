<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UserCleaningMultiDayEventPricingService
{
    public function __construct(
        private readonly UserCleaningOrderEstimationService $estimationService,
    ) {}

    /**
     * @param array<string, mixed> $propertyDetails
     * @param array<int, array{date:string,time:string,hours:float|int|string}> $sessions
     * @return array<string, mixed>
     */
    public function quote(
        array $propertyDetails,
        array $sessions,
        int $workerCount,
        mixed $addressLatitude = null,
        mixed $addressLongitude = null,
        mixed $preferredWorkerId = null,
    ): array {
        if ($sessions === []) {
            throw ValidationException::withMessages([
                'schedule.sessions' => ['At least one event session is required.'],
            ]);
        }

        $workerCount = max(1, min(20, $workerCount));
        $rows = [];
        $basePrice = 0.0;
        $addonsTotal = 0.0;
        $travelFee = 0.0;
        $adminMargin = 0.0;
        $totalPrice = 0.0;
        $totalHours = 0.0;
        $estimatedSqm = 0.0;
        $allFinal = true;
        $hourlyRate = null;
        $recommendation = null;

        foreach (array_values($sessions) as $index => $session) {
            $hours = is_numeric($session['hours'] ?? null) ? (float) $session['hours'] : 0.0;
            if ($hours < 1.0 || $hours > 24.0) {
                throw ValidationException::withMessages([
                    "schedule.sessions.{$index}.hours" => ['Each event session must be between 1 and 24 hours.'],
                ]);
            }

            $details = $propertyDetails;
            $details['hours'] = $hours;
            $details['workerCount'] = $workerCount;

            try {
                $estimation = $this->estimationService->estimate(
                    UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
                    $details,
                );
                $pricing = $this->estimationService->price(
                    UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
                    $details,
                    $addressLatitude,
                    $addressLongitude,
                    $preferredWorkerId,
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'pricing' => [$exception->getMessage()],
                ]);
            }

            $row = [
                'sequence' => $index + 1,
                'date' => (string) ($session['date'] ?? ''),
                'time' => (string) ($session['time'] ?? ''),
                'hours' => round($hours, 2),
                'basePrice' => (float) ($pricing['basePrice'] ?? 0),
                'addonsTotal' => (float) ($pricing['addonsTotal'] ?? 0),
                'travelFee' => (float) ($pricing['travelFee'] ?? 0),
                'distanceKm' => $pricing['distanceKm'] ?? null,
                'adminMargin' => (float) ($pricing['adminMargin'] ?? 0),
                'totalPrice' => (float) ($pricing['totalPrice'] ?? 0),
                'isPricingFinal' => (bool) ($pricing['isPricingFinal'] ?? false),
                'currency' => (string) ($pricing['currency'] ?? config('app.currency', 'SYP')),
            ];

            $rows[] = $row;
            $basePrice += $row['basePrice'];
            $addonsTotal += $row['addonsTotal'];
            $travelFee += $row['travelFee'];
            $adminMargin += $row['adminMargin'];
            $totalPrice += $row['totalPrice'];
            $totalHours += $hours;
            $estimatedSqm = max($estimatedSqm, (float) ($estimation['estimatedSqm'] ?? 0));
            $allFinal = $allFinal && $row['isPricingFinal'];
            $hourlyRate ??= $pricing['eventHourlyRate'] ?? null;
            $recommendation ??= $estimation['recommendation'] ?? null;
        }

        if (is_array($recommendation)) {
            $recommendation['hours'] = round($totalHours, 2);
        }

        return [
            'schedule' => [
                'mode' => count($rows) > 1 ? 'multi_day' : 'single_day',
                'daysCount' => count($rows),
                'totalHours' => round($totalHours, 2),
                'firstDate' => $rows[0]['date'] ?? null,
                'lastDate' => $rows[count($rows) - 1]['date'] ?? null,
                'sessions' => $rows,
            ],
            'size' => [
                'estimatedSqm' => round($estimatedSqm, 2),
                'estimatedHours' => round($totalHours, 2),
            ],
            'pricing' => [
                'basePrice' => round($basePrice, 2),
                'addonsTotal' => round($addonsTotal, 2),
                'travelFee' => round($travelFee, 2),
                'distanceKm' => null,
                'adminMargin' => round($adminMargin, 2),
                'isPricingFinal' => $allFinal,
                'totalPrice' => round($totalPrice, 2),
                'currency' => (string) config('app.currency', 'SYP'),
                'eventHourlyRate' => $hourlyRate,
                'eventHours' => round($totalHours, 2),
                'eventWorkerCount' => $workerCount,
            ],
            'recommendation' => $recommendation,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class EventAssistanceScheduleService
{
    public function __construct(
        private readonly UserCleaningOrderEstimationService $estimationService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{mode:string,sessions:array<int, array{sequence:int,date:string,time:string,hours:float}>,daysCount:int,totalHours:float,firstDate:string,firstTime:string}|null
     */
    public function resolve(array $validated): ?array
    {
        $schedule = $validated['schedule'] ?? null;
        if (! is_array($schedule) || ! is_array($schedule['sessions'] ?? null) || $schedule['sessions'] === []) {
            return null;
        }

        $sessions = [];
        foreach ($schedule['sessions'] as $session) {
            if (! is_array($session)) {
                continue;
            }

            $date = CarbonImmutable::parse((string) ($session['date'] ?? ''))->toDateString();
            $time = mb_trim((string) ($session['time'] ?? ''));
            $hours = $this->normalizeHours((float) ($session['hours'] ?? 1));

            $sessions[] = [
                'date' => $date,
                'time' => $time,
                'hours' => $hours,
            ];
        }

        if ($sessions === []) {
            return null;
        }

        usort($sessions, static function (array $left, array $right): int {
            return [$left['date'], $left['time']] <=> [$right['date'], $right['time']];
        });

        $normalized = [];
        foreach ($sessions as $index => $session) {
            $normalized[] = [
                'sequence' => $index + 1,
                ...$session,
            ];
        }

        $totalHours = round(array_sum(array_column($normalized, 'hours')), 2);
        $first = $normalized[0];

        return [
            'mode' => count($normalized) > 1 ? 'multi_day' : 'single_day',
            'sessions' => $normalized,
            'daysCount' => count($normalized),
            'totalHours' => $totalHours,
            'firstDate' => $first['date'],
            'firstTime' => $first['time'],
        ];
    }

    /**
     * Price every execution session independently and aggregate the parent
     * booking from those immutable session quotes. This preserves the existing
     * one-day pricing algorithm while charging transport for every visit.
     *
     * @param  array{mode:string,sessions:array<int, array{sequence:int,date:string,time:string,hours:float}>,daysCount:int,totalHours:float}  $plan
     * @param  array<string, mixed>  $propertyDetails
     * @return array<string, mixed>
     */
    public function quote(
        array $plan,
        string $propertyType,
        array $propertyDetails,
        mixed $addressLatitude,
        mixed $addressLongitude,
        mixed $preferredWorkerId,
        int $requiredWorkers,
    ): array {
        $basePrice = 0.0;
        $addonsTotal = 0.0;
        $travelFee = 0.0;
        $adminMargin = 0.0;
        $totalPrice = 0.0;
        $distanceKm = null;
        $isPricingFinal = true;
        $currency = (string) config('app.currency', 'SYP');
        $eventHourlyRate = null;
        $scheduleSessions = [];

        foreach ($plan['sessions'] as $session) {
            $sessionDetails = $propertyDetails;
            $sessionDetails['hours'] = (float) $session['hours'];
            $sessionDetails['workerCount'] = max(1, $requiredWorkers);

            $pricing = $this->estimationService->price(
                $propertyType,
                $sessionDetails,
                $addressLatitude,
                $addressLongitude,
                $preferredWorkerId,
            );

            $sessionBase = (float) ($pricing['basePrice'] ?? 0);
            $sessionAddons = (float) ($pricing['addonsTotal'] ?? 0);
            $sessionTravel = (float) ($pricing['travelFee'] ?? 0);
            $sessionAdmin = (float) ($pricing['adminMargin'] ?? 0);
            $sessionTotal = (float) ($pricing['totalPrice'] ?? 0);

            $basePrice += $sessionBase;
            $addonsTotal += $sessionAddons;
            $travelFee += $sessionTravel;
            $adminMargin += $sessionAdmin;
            $totalPrice += $sessionTotal;
            $distanceKm ??= isset($pricing['distanceKm']) ? (float) $pricing['distanceKm'] : null;
            $eventHourlyRate ??= isset($pricing['eventHourlyRate']) ? (float) $pricing['eventHourlyRate'] : null;
            $currency = (string) ($pricing['currency'] ?? $currency);
            $isPricingFinal = $isPricingFinal && (bool) ($pricing['isPricingFinal'] ?? false);

            $scheduleSessions[] = [
                'sequence' => (int) $session['sequence'],
                'date' => (string) $session['date'],
                'time' => (string) $session['time'],
                'hours' => (float) $session['hours'],
                'basePrice' => round($sessionBase, 2),
                'travelFee' => round($sessionTravel, 2),
                'adminMargin' => round($sessionAdmin, 2),
                'totalPrice' => round($sessionTotal, 2),
            ];
        }

        return [
            'basePrice' => round($basePrice, 2),
            'addonsTotal' => round($addonsTotal, 2),
            'travelFee' => round($travelFee, 2),
            'distanceKm' => $distanceKm,
            'adminMargin' => round($adminMargin, 2),
            'isPricingFinal' => $isPricingFinal,
            'totalPrice' => round($totalPrice, 2),
            'currency' => $currency,
            'serviceLines' => [],
            'roomPricingLines' => [],
            'pricingAlgorithm' => null,
            'eventHourlyRate' => $eventHourlyRate,
            'eventHours' => (float) $plan['totalHours'],
            'eventWorkerCount' => max(1, $requiredWorkers),
            'eventExecutionVisits' => (int) $plan['daysCount'],
            'recommendation' => null,
            'schedule' => [
                'mode' => $plan['mode'],
                'daysCount' => $plan['daysCount'],
                'totalHours' => $plan['totalHours'],
                'sessions' => $scheduleSessions,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @param  array{totalHours:float}  $plan
     * @return array<string, mixed>
     */
    public function withAggregateHours(array $propertyDetails, array $plan): array
    {
        $propertyDetails['hours'] = $plan['totalHours'];

        return $propertyDetails;
    }

    /**
     * @param  array{mode:string,sessions:array<int, array{sequence:int,date:string,time:string,hours:float}>,daysCount:int,totalHours:float}  $plan
     * @param  array<string, mixed>  $pricing
     */
    public function createSessions(
        CleaningBooking $booking,
        array $plan,
        array $pricing,
    ): void {
        $schedulePricing = is_array($pricing['schedule']['sessions'] ?? null)
            ? $pricing['schedule']['sessions']
            : [];
        $pricingBySequence = [];

        foreach ($schedulePricing as $sessionPricing) {
            if (! is_array($sessionPricing)) {
                continue;
            }
            $sequence = (int) ($sessionPricing['sequence'] ?? 0);
            if ($sequence > 0) {
                $pricingBySequence[$sequence] = $sessionPricing;
            }
        }

        foreach ($plan['sessions'] as $session) {
            $sequence = (int) $session['sequence'];
            $sessionPricing = $pricingBySequence[$sequence] ?? [];

            CleaningBookingSession::query()->create([
                'cleaning_booking_id' => $booking->id,
                'sequence' => $sequence,
                'session_type' => UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
                'calculation_mode' => 'hours',
                'scheduled_date' => $session['date'],
                'scheduled_time' => $session['time'],
                'duration_hours' => $session['hours'],
                'required_workers' => max(1, (int) $booking->number_of_workers),
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Scheduled,
                'base_price' => (float) ($sessionPricing['basePrice'] ?? 0),
                'addons_total' => 0,
                'materials_total' => 0,
                'special_services_total' => 0,
                'travel_fee' => (float) ($sessionPricing['travelFee'] ?? 0),
                'travel_distance_km' => $booking->travel_distance_km,
                'admin_margin_amount' => (float) ($sessionPricing['adminMargin'] ?? 0),
                'extension_fee_total' => 0,
                'cancellation_fee' => 0,
                'total_price' => (float) ($sessionPricing['totalPrice'] ?? 0),
                'is_pricing_final' => (bool) $booking->is_pricing_final,
                'pricing_snapshot' => [
                    'eventHourlyRate' => (float) ($pricing['eventHourlyRate'] ?? 0),
                    'requiredWorkers' => max(1, (int) $booking->number_of_workers),
                    'scheduleMode' => $plan['mode'],
                    'parentBasePrice' => (float) $booking->base_price,
                    'parentTravelFee' => (float) $booking->travel_fee,
                    'parentAdminMargin' => (float) $booking->admin_margin_amount,
                    'currency' => (string) ($pricing['currency'] ?? config('app.currency', 'SYP')),
                ],
            ]);
        }
    }

    private function normalizeHours(float $hours): float
    {
        return ceil(max(1.0, $hours) * 2) / 2;
    }
}

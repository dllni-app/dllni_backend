<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningPricingCalculator;

final class EventAssistanceScheduleService
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

    /**
     * @param array<string, mixed> $validated
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
     * @param array<string, mixed> $propertyDetails
     * @param array{totalHours:float} $plan
     * @return array<string, mixed>
     */
    public function withAggregateHours(array $propertyDetails, array $plan): array
    {
        $propertyDetails['hours'] = $plan['totalHours'];

        return $propertyDetails;
    }

    /**
     * @param array{mode:string,sessions:array<int, array{sequence:int,date:string,time:string,hours:float}>,daysCount:int,totalHours:float} $plan
     * @param array<string, mixed> $pricing
     * @return array<string, mixed>
     */
    public function pricingPayload(array $plan, array $pricing, int $requiredWorkers): array
    {
        $sessions = $plan['sessions'];
        $count = max(1, count($sessions));
        $parentBase = max(0.0, (float) ($pricing['basePrice'] ?? 0));
        $parentAdmin = max(0.0, (float) ($pricing['adminMargin'] ?? 0));
        $parentTravel = max(0.0, (float) ($pricing['travelFee'] ?? 0));
        $hourlyRate = max(0.0, (float) ($pricing['eventHourlyRate'] ?? 0));
        $baseAllocated = 0.0;
        $adminAllocated = 0.0;
        $travelAllocated = 0.0;
        $payloadSessions = [];

        foreach ($sessions as $index => $session) {
            $isLast = $index === $count - 1;
            $calculatedBase = $this->pricingCalculator->roundMoney(
                $hourlyRate * (float) $session['hours'] * max(1, $requiredWorkers),
            );
            $base = $isLast
                ? round(max(0.0, $parentBase - $baseAllocated), 2)
                : min($calculatedBase, round(max(0.0, $parentBase - $baseAllocated), 2));
            $baseAllocated += $base;

            $admin = $this->proportionalShare(
                total: $parentAdmin,
                part: $base,
                whole: $parentBase,
                allocated: $adminAllocated,
                isLast: $isLast,
            );
            $adminAllocated += $admin;

            $travel = $this->equalShare(
                total: $parentTravel,
                count: $count,
                allocated: $travelAllocated,
                isLast: $isLast,
            );
            $travelAllocated += $travel;

            $payloadSessions[] = [
                'sequence' => (int) $session['sequence'],
                'date' => (string) $session['date'],
                'time' => (string) $session['time'],
                'hours' => (float) $session['hours'],
                'basePrice' => round($base, 2),
                'travelFee' => round($travel, 2),
                'adminMargin' => round($admin, 2),
                'totalPrice' => round($base + $travel + $admin, 2),
            ];
        }

        return [
            'mode' => $plan['mode'],
            'daysCount' => $plan['daysCount'],
            'totalHours' => $plan['totalHours'],
            'sessions' => $payloadSessions,
        ];
    }

    /**
     * @param array{mode:string,sessions:array<int, array{sequence:int,date:string,time:string,hours:float}>,daysCount:int,totalHours:float} $plan
     * @param array<string, mixed> $pricing
     */
    public function createSessions(
        CleaningBooking $booking,
        array $plan,
        array $pricing,
    ): void {
        $schedulePricing = $this->pricingPayload(
            $plan,
            $pricing,
            max(1, (int) $booking->number_of_workers),
        );

        foreach ($schedulePricing['sessions'] as $session) {
            CleaningBookingSession::query()->create([
                'cleaning_booking_id' => $booking->id,
                'sequence' => $session['sequence'],
                'session_type' => UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
                'calculation_mode' => 'hours',
                'scheduled_date' => $session['date'],
                'scheduled_time' => $session['time'],
                'duration_hours' => $session['hours'],
                'required_workers' => max(1, (int) $booking->number_of_workers),
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Scheduled,
                'base_price' => $session['basePrice'],
                'addons_total' => 0,
                'materials_total' => 0,
                'special_services_total' => 0,
                'travel_fee' => $session['travelFee'],
                'travel_distance_km' => $booking->travel_distance_km,
                'admin_margin_amount' => $session['adminMargin'],
                'extension_fee_total' => 0,
                'cancellation_fee' => 0,
                'total_price' => $session['totalPrice'],
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

    private function proportionalShare(
        float $total,
        float $part,
        float $whole,
        float $allocated,
        bool $isLast,
    ): float {
        if ($total <= 0.0 || $whole <= 0.0) {
            return 0.0;
        }

        if ($isLast) {
            return round(max(0.0, $total - $allocated), 2);
        }

        return round($total * ($part / $whole), 2);
    }

    private function equalShare(float $total, int $count, float $allocated, bool $isLast): float
    {
        if ($total <= 0.0) {
            return 0.0;
        }

        if ($isLast) {
            return round(max(0.0, $total - $allocated), 2);
        }

        return round($total / max(1, $count), 2);
    }
}

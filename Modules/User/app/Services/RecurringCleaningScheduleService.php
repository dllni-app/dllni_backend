<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class RecurringCleaningScheduleService
{
    public const SESSION_TYPE = 'recurring_cleaning';

    public const CALCULATION_TASK = 'task';

    public const CALCULATION_HOURS = 'hours';

    /**
     * @param  array<string, mixed>  $validated
     * @return array{mode:string,sessions:array<int,array{sequence:int,date:string,time:string}>,sessionsCount:int,firstDate:string,firstTime:string}|null
     */
    public function resolve(array $validated): ?array
    {
        $schedule = $validated['schedule'] ?? null;
        if (! is_array($schedule) || ! is_array($schedule['sessions'] ?? null)) {
            return null;
        }

        if (mb_strtolower(mb_trim((string) ($schedule['mode'] ?? ''))) !== 'recurring') {
            return null;
        }

        $calculationMode = mb_strtolower(mb_trim((string) ($schedule['calculationMode'] ?? self::CALCULATION_TASK)));
        if (! in_array($calculationMode, [self::CALCULATION_TASK, self::CALCULATION_HOURS], true)) {
            return null;
        }
        $hoursPerVisit = $calculationMode === self::CALCULATION_HOURS
            ? $this->normalizeHours((float) ($schedule['hoursPerVisit'] ?? 0))
            : null;
        if ($calculationMode === self::CALCULATION_HOURS && ($hoursPerVisit === null || $hoursPerVisit < 1.0)) {
            return null;
        }

        $sessions = [];
        foreach ($schedule['sessions'] as $session) {
            if (! is_array($session)) {
                continue;
            }

            $date = CarbonImmutable::parse((string) ($session['date'] ?? ''))->toDateString();
            $time = mb_trim((string) ($session['time'] ?? ''));

            $sessions[] = [
                'date' => $date,
                'time' => $time,
            ];
        }

        if (count($sessions) < 2) {
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

        return [
            'mode' => 'multi_day',
            'sessions' => $normalized,
            'sessionsCount' => count($normalized),
            'calculationMode' => $calculationMode,
            'hoursPerVisit' => $hoursPerVisit,
            'firstDate' => $normalized[0]['date'],
            'firstTime' => $normalized[0]['time'],
        ];
    }

    /**
     * Aggregate one normal-cleaning quote across every recurring execution visit.
     * Each child visit keeps the normal single-booking pricing shape so session
     * acceptance can finalize worker-specific travel and commission independently.
     *
     * @param  array{mode:string,sessions:array<int,array{sequence:int,date:string,time:string}>,sessionsCount:int}  $plan
     * @param  array<string, mixed>  $singleVisitPricing
     * @return array<string, mixed>
     */
    public function quote(array $plan, array $singleVisitPricing, float $sessionHours): array
    {
        $count = max(1, (int) $plan['sessionsCount']);
        $basePrice = (float) ($singleVisitPricing['basePrice'] ?? 0);
        $addonsTotal = (float) ($singleVisitPricing['addonsTotal'] ?? 0);
        $travelFee = (float) ($singleVisitPricing['travelFee'] ?? 0);
        $adminMargin = (float) ($singleVisitPricing['adminMargin'] ?? 0);
        $totalPrice = (float) ($singleVisitPricing['totalPrice'] ?? 0);

        $scheduleSessions = array_map(
            static fn (array $session): array => [
                'sequence' => (int) $session['sequence'],
                'date' => (string) $session['date'],
                'time' => (string) $session['time'],
                'hours' => round($sessionHours, 2),
                'basePrice' => round($basePrice, 2),
                'addonsTotal' => round($addonsTotal, 2),
                'travelFee' => round($travelFee, 2),
                'adminMargin' => round($adminMargin, 2),
                'totalPrice' => round($totalPrice, 2),
            ],
            $plan['sessions'],
        );

        return [
            ...$singleVisitPricing,
            'basePrice' => round($basePrice * $count, 2),
            'addonsTotal' => round($addonsTotal * $count, 2),
            'travelFee' => round($travelFee * $count, 2),
            'adminMargin' => round($adminMargin * $count, 2),
            'totalPrice' => round($totalPrice * $count, 2),
            'recurringOccurrences' => $count,
            'recurringSessionHours' => round($sessionHours, 2),
            'schedule' => [
                'mode' => 'multi_day',
                'scheduleType' => self::SESSION_TYPE,
                'isRecurring' => true,
                'isMultiSession' => true,
                'calculationMode' => (string) ($plan['calculationMode'] ?? self::CALCULATION_TASK),
                'hoursPerVisit' => isset($plan['hoursPerVisit']) ? (float) $plan['hoursPerVisit'] : null,
                'sessionsCount' => $count,
                'daysCount' => $count,
                'totalHours' => round($sessionHours * $count, 2),
                'sessions' => $scheduleSessions,
            ],
        ];
    }

    /**
     * @param  array{mode:string,sessions:array<int,array{sequence:int,date:string,time:string}>,sessionsCount:int,firstDate:string,firstTime:string}  $plan
     */
    public function materialize(CleaningBooking $booking, array $plan): CleaningBooking
    {
        $count = max(1, (int) $plan['sessionsCount']);
        $calculationMode = (string) ($plan['calculationMode'] ?? self::CALCULATION_TASK);
        $sessionHours = $calculationMode === self::CALCULATION_HOURS
            ? max(1.0, (float) ($plan['hoursPerVisit'] ?? $booking->estimated_hours))
            : max(0.0, (float) $booking->estimated_hours);
        $basePrice = (float) $booking->base_price;
        $addonsTotal = (float) $booking->addons_total;
        $travelFee = (float) $booking->travel_fee;
        $adminMargin = (float) $booking->admin_margin_amount;
        $totalPrice = (float) $booking->total_price;

        $booking->forceFill([
            'scheduled_date' => $plan['firstDate'],
            'scheduled_time' => $plan['firstTime'],
            'estimated_hours' => round($sessionHours * $count, 2),
            'total_hours' => round($sessionHours * $count, 2),
            'base_price' => round($basePrice * $count, 2),
            'addons_total' => round($addonsTotal * $count, 2),
            'travel_fee' => round($travelFee * $count, 2),
            'admin_margin_amount' => round($adminMargin * $count, 2),
            'total_price' => round($totalPrice * $count, 2),
        ])->save();

        foreach ($plan['sessions'] as $session) {
            CleaningBookingSession::query()->create([
                'cleaning_booking_id' => $booking->id,
                'sequence' => (int) $session['sequence'],
                'session_type' => self::SESSION_TYPE,
                'calculation_mode' => $calculationMode,
                'scheduled_date' => $session['date'],
                'scheduled_time' => $session['time'],
                'duration_hours' => $sessionHours,
                'required_workers' => max(1, (int) $booking->number_of_workers),
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Scheduled,
                'base_price' => $basePrice,
                'addons_total' => $addonsTotal,
                'materials_total' => 0,
                'special_services_total' => 0,
                'travel_fee' => $travelFee,
                'travel_distance_km' => $booking->travel_distance_km,
                'admin_margin_amount' => $adminMargin,
                'extension_fee_total' => 0,
                'cancellation_fee' => 0,
                'total_price' => $totalPrice,
                'is_pricing_final' => (bool) $booking->is_pricing_final,
                'pricing_snapshot' => [
                    'scheduleType' => self::SESSION_TYPE,
                    'scheduleMode' => 'multi_day',
                    'occurrencesCount' => $count,
                    'calculationMode' => $calculationMode,
                    'hoursPerVisit' => $calculationMode === self::CALCULATION_HOURS ? round($sessionHours, 2) : null,
                    'perVisitEstimatedHours' => round($sessionHours, 2),
                    'requiredWorkers' => max(1, (int) $booking->number_of_workers),
                    'currency' => (string) config('app.currency', 'SYP'),
                ],
            ]);
        }

        return $booking->fresh() ?? $booking;
    }

    private function normalizeHours(float $hours): ?float
    {
        if ($hours < 1.0 || $hours > 24.0) {
            return null;
        }

        return ceil($hours * 2) / 2;
    }
}

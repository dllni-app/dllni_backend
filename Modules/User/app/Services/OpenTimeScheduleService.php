<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class OpenTimeScheduleService
{
    public const SESSION_TYPE = 'open_time';

    /**
     * @param  array<string, mixed>  $validated
     * @return array{mode:string,sessions:array<int,array{sequence:int,date:string,time:string,expectedMaxMinutes:int}>,sessionsCount:int,firstDate:string,firstTime:string,totalExpectedMinutes:int}|null
     */
    public function resolve(array $validated): ?array
    {
        $openTime = $validated['openTime'] ?? null;
        if (! is_array($openTime) || ! is_array($openTime['sessions'] ?? null)) {
            return null;
        }

        $defaultMinutes = max(15, min(480, (int) ($openTime['expectedMaxMinutes'] ?? 480)));
        $sessions = [];
        $uniqueSlots = [];

        foreach ($openTime['sessions'] as $session) {
            if (! is_array($session)) {
                continue;
            }

            $date = CarbonImmutable::parse((string) ($session['date'] ?? ''))->toDateString();
            $time = mb_trim((string) ($session['time'] ?? ''));
            $slot = $date.' '.$time;
            if (isset($uniqueSlots[$slot])) {
                throw ValidationException::withMessages([
                    'openTime.sessions' => ['Open-Time sessions must use distinct date and time slots.'],
                ]);
            }
            $uniqueSlots[$slot] = true;
            $sessions[] = [
                'date' => $date,
                'time' => $time,
                'expectedMaxMinutes' => max(15, min(480, (int) ($session['expectedMaxMinutes'] ?? $defaultMinutes))),
            ];
        }

        if ($sessions === []) {
            return null;
        }

        usort($sessions, static fn (array $left, array $right): int => [
            $left['date'],
            $left['time'],
        ] <=> [
            $right['date'],
            $right['time'],
        ]);

        $normalized = [];
        foreach ($sessions as $index => $session) {
            $normalized[] = ['sequence' => $index + 1, ...$session];
        }

        return [
            'mode' => count($normalized) > 1 ? 'multi_day' : 'single_day',
            'sessions' => $normalized,
            'sessionsCount' => count($normalized),
            'firstDate' => $normalized[0]['date'],
            'firstTime' => $normalized[0]['time'],
            'totalExpectedMinutes' => array_sum(array_column($normalized, 'expectedMaxMinutes')),
        ];
    }

    /**
     * @param  array{sessions:array<int,array{sequence:int,date:string,time:string,expectedMaxMinutes:int}>,sessionsCount:int,totalExpectedMinutes:int}  $plan
     * @param  array<string, mixed>  $singleSessionPricing
     * @return array<string, mixed>
     */
    public function quote(array $plan, array $singleSessionPricing): array
    {
        $count = max(1, (int) $plan['sessionsCount']);
        $openTime = (array) ($singleSessionPricing['openTime'] ?? []);
        $singleBase = max(0.0, (float) ($singleSessionPricing['basePrice'] ?? 0));
        $singleTravel = max(0.0, (float) ($singleSessionPricing['travelFee'] ?? 0));
        $singleAdmin = max(0.0, (float) ($singleSessionPricing['adminMargin'] ?? 0));
        $sessions = [];

        foreach ($plan['sessions'] as $session) {
            $expectedMinutes = (int) $session['expectedMaxMinutes'];
            $hourlyRate = max(0.0, (float) ($openTime['hourlyRate'] ?? 0));
            $workerCount = max(1, (int) ($openTime['requestedWorkerCount'] ?? 1));
            $maximumAmount = round($hourlyRate * $workerCount * ($expectedMinutes / 60), 2);
            $sessions[] = [
                ...$session,
                'hours' => round($expectedMinutes / 60, 2),
                'basePrice' => round($singleBase, 2),
                'addonsTotal' => 0.0,
                'travelFee' => round($singleTravel, 2),
                'adminMargin' => round($singleAdmin, 2),
                'totalPrice' => round($singleBase + $singleTravel + $singleAdmin, 2),
                'maximumEstimatedAmount' => $maximumAmount,
            ];
        }

        return [
            ...$singleSessionPricing,
            'basePrice' => round($singleBase * $count, 2),
            'travelFee' => round($singleTravel * $count, 2),
            'adminMargin' => round($singleAdmin * $count, 2),
            'totalPrice' => round(($singleBase + $singleTravel + $singleAdmin) * $count, 2),
            'openTime' => [
                ...$openTime,
                'isMultiSession' => $count > 1,
                'sessionsCount' => $count,
                'totalExpectedMinutes' => (int) $plan['totalExpectedMinutes'],
                'sessions' => $sessions,
            ],
            'schedule' => [
                'mode' => $count > 1 ? 'multi_day' : 'single_day',
                'scheduleType' => self::SESSION_TYPE,
                'isOpenTime' => true,
                'isMultiSession' => $count > 1,
                'sessionsCount' => $count,
                'daysCount' => $count,
                'totalHours' => round(((int) $plan['totalExpectedMinutes']) / 60, 2),
                'sessions' => $sessions,
            ],
        ];
    }

    /**
     * @param  array{sessions:array<int,array{sequence:int,date:string,time:string,expectedMaxMinutes:int}>,sessionsCount:int,firstDate:string,firstTime:string,totalExpectedMinutes:int}  $plan
     * @param  array<string, mixed>  $aggregatePricing
     */
    public function materialize(CleaningBooking $booking, array $plan, array $aggregatePricing): CleaningBooking
    {
        $sessionPricing = collect((array) ($aggregatePricing['schedule']['sessions'] ?? []))->keyBy('sequence');
        $openTime = (array) ($aggregatePricing['openTime'] ?? []);

        $booking->forceFill([
            'scheduled_date' => $plan['firstDate'],
            'scheduled_time' => $plan['firstTime'],
            'estimated_hours' => round(((int) $plan['totalExpectedMinutes']) / 60, 2),
            'total_hours' => round(((int) $plan['totalExpectedMinutes']) / 60, 2),
            'base_price' => (float) ($aggregatePricing['basePrice'] ?? $booking->base_price),
            'travel_fee' => (float) ($aggregatePricing['travelFee'] ?? $booking->travel_fee),
            'admin_margin_amount' => (float) ($aggregatePricing['adminMargin'] ?? $booking->admin_margin_amount),
            'total_price' => (float) ($aggregatePricing['totalPrice'] ?? $booking->total_price),
        ])->save();

        foreach ($plan['sessions'] as $session) {
            $pricing = (array) ($sessionPricing->get((int) $session['sequence']) ?? []);
            CleaningBookingSession::query()->create([
                'cleaning_booking_id' => $booking->id,
                'sequence' => (int) $session['sequence'],
                'session_type' => self::SESSION_TYPE,
                'calculation_mode' => 'actual_time',
                'scheduled_date' => $session['date'],
                'scheduled_time' => $session['time'],
                'duration_hours' => round(((int) $session['expectedMaxMinutes']) / 60, 2),
                'open_time_expected_max_minutes' => (int) $session['expectedMaxMinutes'],
                'open_time_hard_max_minutes' => (int) ($openTime['hardMaxMinutes'] ?? 480),
                'required_workers' => max(1, (int) $booking->number_of_workers),
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Scheduled,
                'base_price' => (float) ($pricing['basePrice'] ?? 0),
                'addons_total' => 0,
                'materials_total' => 0,
                'special_services_total' => 0,
                'travel_fee' => (float) ($pricing['travelFee'] ?? 0),
                'travel_distance_km' => $booking->travel_distance_km,
                'admin_margin_amount' => (float) ($pricing['adminMargin'] ?? 0),
                'extension_fee_total' => 0,
                'cancellation_fee' => 0,
                'total_price' => (float) ($pricing['totalPrice'] ?? 0),
                'is_pricing_final' => (bool) $booking->is_pricing_final,
                'pricing_snapshot' => [
                    'scheduleType' => self::SESSION_TYPE,
                    'hourlyRate' => (float) ($openTime['hourlyRate'] ?? 0),
                    'workerCount' => max(1, (int) ($openTime['requestedWorkerCount'] ?? $booking->number_of_workers)),
                    'minimumBillableMinutes' => (int) ($openTime['minimumBillableMinutes'] ?? 60),
                    'roundingMinutes' => (int) ($openTime['roundingMinutes'] ?? 15),
                    'expectedMaxMinutes' => (int) $session['expectedMaxMinutes'],
                    'hardMaxMinutes' => (int) ($openTime['hardMaxMinutes'] ?? 480),
                    'warningMinutes' => (int) ($openTime['warningMinutes'] ?? 30),
                    'extensionOptions' => array_values((array) ($openTime['extensionOptions'] ?? [15, 30, 60])),
                    'maximumEstimatedAmount' => (float) ($pricing['maximumEstimatedAmount'] ?? 0),
                    'currency' => (string) ($aggregatePricing['currency'] ?? config('app.currency', 'SYP')),
                ],
            ]);
        }

        return $booking->fresh() ?? $booking;
    }
}

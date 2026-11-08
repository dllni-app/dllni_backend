<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Restaurant;

final class RestaurantPickupWindowPolicy
{
    public function timezone(): string
    {
        return (string) config('restaurant_v2.timezone', 'Asia/Damascus');
    }

    /** @return array{restaurantId:int,date:string,timezone:string,selectionMode:string,status:string,windows:array,reasonCode:?string} */
    public function forDate(Restaurant $restaurant, string $date): array
    {
        $tz = $this->timezone();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'Invalid date.']);
        }

        $windows = [];
        $hours = $restaurant->operatingHours;
        $known = $hours->isNotEmpty() && $hours->every(static fn ($row): bool => $row->is_closed || (
            is_string($row->open_time) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $row->open_time) === 1 &&
            is_string($row->close_time) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $row->close_time) === 1
        ));
        $blocked = ! $restaurant->is_active || $restaurant->is_temporarily_closed ||
            ($restaurant->suspension_until !== null && $restaurant->suspension_until->isAfter($day->endOfDay()));

        if (! $blocked && $known) {
            foreach ([$day->subDay(), $day] as $sourceDay) {
                foreach ($hours as $row) {
                    $weekday = $row->day_of_week?->value ?? $row->day_of_week;
                    if ($row->is_closed || $weekday !== mb_strtolower($sourceDay->englishDayOfWeek)) {
                        continue;
                    }
                    $start = CarbonImmutable::parse($sourceDay->format('Y-m-d').' '.$row->open_time, $tz);
                    $end = CarbonImmutable::parse($sourceDay->format('Y-m-d').' '.$row->close_time, $tz);
                    if ($end->lessThanOrEqualTo($start)) {
                        $end = $end->addDay();
                    }
                    $left = $start->greaterThan($day) ? $start : $day;
                    $right = $end->lessThan($day->addDay()) ? $end : $day->addDay();
                    if ($restaurant->suspension_until !== null && $restaurant->suspension_until->greaterThan($left)) {
                        $left = CarbonImmutable::instance($restaurant->suspension_until)->setTimezone($tz);
                    }
                    if ($left->lessThan($right)) {
                        $windows[] = ['startsAt' => $left->toIso8601String(), 'endsAt' => $right->toIso8601String()];
                    }
                }
            }
            usort($windows, static fn (array $a, array $b): int => strcmp($a['startsAt'], $b['startsAt']));
        }

        $status = $blocked ? 'closed' : (! $known ? 'unknown' : ($windows === [] ? 'closed' : 'available'));

        return ['restaurantId' => (int) $restaurant->id, 'date' => $date, 'timezone' => $tz,
            'selectionMode' => 'requested_time', 'status' => $status, 'windows' => $windows,
            'reasonCode' => match ($status) {
                'unknown' => 'PICKUP_HOURS_UNKNOWN', 'closed' => 'PICKUP_WINDOW_CLOSED', default => null
            }];
    }

    public function assertScheduled(Restaurant $restaurant, string $instant): void
    {
        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $instant) !== 1) {
            throw ValidationException::withMessages(['scheduledAt' => 'PICKUP_TIME_OFFSET_REQUIRED']);
        }
        $at = CarbonImmutable::parse($instant);
        if ($at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['scheduledAt' => 'PICKUP_TIME_IN_PAST']);
        }
        $local = $at->setTimezone($this->timezone());
        $result = $this->forDate($restaurant->loadMissing('operatingHours'), $local->format('Y-m-d'));
        if ($result['status'] !== 'available') {
            throw ValidationException::withMessages(['scheduledAt' => $result['reasonCode']]);
        }
        foreach ($result['windows'] as $window) {
            if ($at->greaterThanOrEqualTo(CarbonImmutable::parse($window['startsAt'])) && $at->lessThan(CarbonImmutable::parse($window['endsAt']))) {
                return;
            }
        }
        throw ValidationException::withMessages(['scheduledAt' => 'PICKUP_WINDOW_CLOSED']);
    }
}

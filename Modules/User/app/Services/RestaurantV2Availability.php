<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Models\Restaurant;

final class RestaurantV2Availability
{
    public static function isOpen(Restaurant $restaurant, ?CarbonInterface $at = null): ?bool
    {
        $timezone = (string) config('restaurant_v2.timezone', 'Asia/Damascus');
        $at = $at === null ? CarbonImmutable::now($timezone) : CarbonImmutable::instance($at)->setTimezone($timezone);

        if (! $restaurant->is_active || $restaurant->is_temporarily_closed) {
            return false;
        }

        if ($restaurant->suspension_until !== null && $restaurant->suspension_until->isAfter($at)) {
            return false;
        }

        $hours = $restaurant->relationLoaded('operatingHours')
            ? $restaurant->operatingHours
            : $restaurant->operatingHours()->get();
        if ($hours->isEmpty()) {
            return null;
        }

        $knownSchedule = $hours->contains(fn (OperatingHour $row): bool => self::hasUsableSchedule($row));
        if (! $knownSchedule) {
            return null;
        }

        $today = Str::lower($at->englishDayOfWeek);
        $yesterday = Str::lower($at->copy()->subDay()->englishDayOfWeek);
        $time = $at->format('H:i:s');

        return $hours->contains(fn (OperatingHour $row): bool => self::rowIsOpen($row, $today, $yesterday, $time));
    }

    public static function constrainOpen(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $timezone = (string) config('restaurant_v2.timezone', 'Asia/Damascus');
        $at = $at === null ? CarbonImmutable::now($timezone) : CarbonImmutable::instance($at)->setTimezone($timezone);
        $today = Str::lower($at->englishDayOfWeek);
        $yesterday = Str::lower($at->copy()->subDay()->englishDayOfWeek);
        $time = $at->format('H:i:s');

        return $query
            ->where('restaurants.is_active', true)
            ->where('restaurants.is_temporarily_closed', false)
            ->where(fn (Builder $q): Builder => $q->whereNull('restaurants.suspension_until')->orWhere('restaurants.suspension_until', '<=', $at))
            ->where(function (Builder $q) use ($today, $yesterday, $time): void {
                $q->whereHas('operatingHours', function (Builder $hours) use ($today, $time): void {
                    $hours->where('day_of_week', $today)
                        ->where('is_closed', false)
                        ->whereNotNull('open_time')
                        ->whereNotNull('close_time')
                        ->where(function (Builder $interval) use ($time): void {
                            $interval->where(function (Builder $sameDay) use ($time): void {
                                $sameDay->whereColumn('open_time', '<', 'close_time')
                                    ->where('open_time', '<=', $time)
                                    ->where('close_time', '>', $time);
                            })->orWhere(function (Builder $overnight) use ($time): void {
                                $overnight->whereColumn('open_time', '>', 'close_time')
                                    ->where('open_time', '<=', $time);
                            });
                        });
                })->orWhereHas('operatingHours', function (Builder $hours) use ($yesterday, $time): void {
                    $hours->where('day_of_week', $yesterday)
                        ->where('is_closed', false)
                        ->whereColumn('open_time', '>', 'close_time')
                        ->whereNotNull('open_time')
                        ->whereNotNull('close_time')
                        ->where('close_time', '>', $time);
                });
            });
    }

    private static function rowIsOpen(OperatingHour $row, string $today, string $yesterday, string $time): bool
    {
        if ($row->is_closed || $row->open_time === null || $row->close_time === null) {
            return false;
        }

        $day = $row->getAttributes()['day_of_week'] ?? null;

        if ($day === $today) {
            if ($row->open_time < $row->close_time) {
                return $row->open_time <= $time && $time < $row->close_time;
            }

            return $row->open_time > $row->close_time && $row->open_time <= $time;
        }

        return $day === $yesterday && $row->open_time > $row->close_time && $time < $row->close_time;
    }

    private static function hasUsableSchedule(OperatingHour $row): bool
    {
        $day = $row->getAttributes()['day_of_week'] ?? null;
        if (! is_string($day) || ! in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
            return false;
        }

        if ($row->is_closed) {
            return true;
        }

        return is_string($row->open_time) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $row->open_time) === 1
            && is_string($row->close_time) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $row->close_time) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Data\SmSmartListData;
use Modules\Supermarket\Models\SmSmartList;
use Modules\Supermarket\Models\SmSmartListSchedule;

final class SmSmartListService
{
    public function store(SmSmartListData $data): SmSmartList
    {
        return DB::transaction(function () use ($data) {
            $list = SmSmartList::create($data->onlyModelAttributes());

            $this->upsertSchedule($list, $data->schedule);

            return $list;
        });
    }

    public function update(SmSmartListData $data, SmSmartList $list): SmSmartList
    {
        return DB::transaction(function () use ($data, $list) {
            tap($list)->update($data->onlyModelAttributes());

            $this->upsertSchedule($list->refresh(), $data->schedule);

            return $list;
        });
    }

    private function upsertSchedule(SmSmartList $list, ?array $schedule): void
    {
        if ($schedule === null) {
            return;
        }

        $frequencyType = $schedule['frequency_type'] ?? $schedule['frequencyType'] ?? null;
        $isActive = (bool) ($schedule['is_active'] ?? $schedule['isActive'] ?? true);
        $weekDays = $this->normalizeIntegerList(
            $schedule['week_days'] ?? $schedule['weekDays'] ?? $schedule['dayOfWeek'] ?? null
        );
        $monthDays = $this->normalizeIntegerList(
            $schedule['month_days'] ?? $schedule['monthDays'] ?? $schedule['dayOfMonth'] ?? null
        );
        $periods = $this->normalizePeriods($schedule['periods'] ?? null);

        if ($list->store_id === null && $isActive) {
            throw ValidationException::withMessages([
                'storeId' => ['A store is required when schedule is active.'],
            ]);
        }

        if ($frequencyType === 'weekly' && $weekDays === []) {
            throw ValidationException::withMessages([
                'schedule.weekDays' => ['At least one weekday must be selected for weekly schedules.'],
            ]);
        }

        if ($frequencyType === 'monthly' && $monthDays === []) {
            throw ValidationException::withMessages([
                'schedule.monthDays' => ['At least one month day must be selected for monthly schedules.'],
            ]);
        }

        if ($periods === []) {
            throw ValidationException::withMessages([
                'schedule.periods' => ['At least one time period is required.'],
            ]);
        }

        $nextRunAt = $isActive
            ? $this->calculateNextRunAt($frequencyType, $weekDays, $monthDays, $periods)
            : null;

        SmSmartListSchedule::query()->updateOrCreate(
            ['smart_list_id' => $list->id],
            [
                'frequency_type' => $frequencyType,
                'week_days' => $weekDays,
                'month_days' => $monthDays,
                'periods' => $periods,
                'is_active' => $isActive,
                'next_run_at' => $nextRunAt,
            ]
        );
    }

    /**
     * @param  array<int, int>  $weekDays
     * @param  array<int, int>  $monthDays
     * @param  array<int, array{label?: string|null, fromTime: string, toTime: string}>  $periods
     */
    private function calculateNextRunAt(?string $frequencyType, array $weekDays, array $monthDays, array $periods): ?Carbon
    {
        if ($frequencyType === null) {
            return null;
        }

        $now = now();
        $startTime = $this->earliestPeriodStartTime($periods);

        if ($startTime === null) {
            return null;
        }

        if ($frequencyType === 'weekly' && $weekDays !== []) {
            return $this->nextWeeklyRunAt($weekDays, $startTime, $now);
        }

        if ($frequencyType === 'monthly' && $monthDays !== []) {
            return $this->nextMonthlyRunAt($monthDays, $startTime, $now);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>|int|string|null  $value
     * @return array<int, int>
     */
    private function normalizeIntegerList(array|int|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_map(static fn (mixed $item): int => (int) $item, $items)));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $periods
     * @return array<int, array{label?: string|null, fromTime: string, toTime: string}>
     */
    private function normalizePeriods(?array $periods): array
    {
        if ($periods === null) {
            return [];
        }

        return array_values(array_map(
            static function (array $period): array {
                return [
                    'label' => isset($period['label']) ? (string) $period['label'] : null,
                    'fromTime' => (string) ($period['fromTime'] ?? $period['from_time'] ?? ''),
                    'toTime' => (string) ($period['toTime'] ?? $period['to_time'] ?? ''),
                ];
            },
            $periods
        ));
    }

    /**
     * @param  array<int, array{label?: string|null, fromTime: string, toTime: string}>  $periods
     */
    private function earliestPeriodStartTime(array $periods): ?string
    {
        $filtered = array_filter($periods, static fn (array $period): bool => $period['fromTime'] !== '');

        if ($filtered === []) {
            return null;
        }

        usort($filtered, static fn (array $left, array $right): int => strcmp($left['fromTime'], $right['fromTime']));

        return $filtered[0]['fromTime'];
    }

    /**
     * @param  array<int, int>  $weekDays
     */
    private function nextWeeklyRunAt(array $weekDays, string $startTime, CarbonInterface $now): ?Carbon
    {
        $weekDays = array_values(array_unique(array_map(static fn (int $day): int => max(0, min(6, $day)), $weekDays)));

        for ($offset = 0; $offset <= 14; $offset++) {
            $candidateDate = $now->copy()->startOfDay()->addDays($offset);

            if (! in_array($candidateDate->dayOfWeek, $weekDays, true)) {
                continue;
            }

            $candidate = Carbon::parse($candidateDate->toDateString().' '.$startTime);

            if ($candidate->gt($now)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $monthDays
     */
    private function nextMonthlyRunAt(array $monthDays, string $startTime, CarbonInterface $now): ?Carbon
    {
        $monthDays = array_values(array_unique(array_map(static fn (int $day): int => max(1, min(31, $day)), $monthDays)));

        for ($monthOffset = 0; $monthOffset <= 12; $monthOffset++) {
            $month = $now->copy()->startOfMonth()->addMonthsNoOverflow($monthOffset);
            $lastDay = (int) $month->copy()->endOfMonth()->day;

            foreach ($monthDays as $day) {
                $candidateDay = min($day, $lastDay);
                $candidate = Carbon::parse($month->copy()->day($candidateDay)->toDateString().' '.$startTime);

                if ($candidate->gt($now)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}

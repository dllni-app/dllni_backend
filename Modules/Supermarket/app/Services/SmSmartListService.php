<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
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

        if ($list->store_id === null && $isActive) {
            throw ValidationException::withMessages([
                'storeId' => ['A store is required when schedule is active.'],
            ]);
        }

        $runDate = $schedule['run_date'] ?? $schedule['runDate'] ?? null;
        $dayOfWeek = $schedule['day_of_week'] ?? $schedule['dayOfWeek'] ?? null;
        $dayOfMonth = $schedule['day_of_month'] ?? $schedule['dayOfMonth'] ?? null;

        $nextRunAt = $isActive
            ? $this->calculateNextRunAt($frequencyType, $dayOfWeek, $dayOfMonth, $runDate)
            : null;

        SmSmartListSchedule::query()->updateOrCreate(
            ['smart_list_id' => $list->id],
            [
                'frequency_type' => $frequencyType,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => $dayOfMonth,
                'run_date' => $runDate,
                'is_active' => $isActive,
                'next_run_at' => $nextRunAt,
            ]
        );
    }

    private function calculateNextRunAt(?string $frequencyType, mixed $dayOfWeek, mixed $dayOfMonth, mixed $runDate): ?Carbon
    {
        if ($frequencyType === null) {
            return null;
        }

        $now = now();

        if ($frequencyType === 'once') {
            return $runDate !== null ? Carbon::parse((string) $runDate)->startOfDay() : null;
        }

        if ($frequencyType === 'weekly' && $dayOfWeek !== null) {
            $target = (int) $dayOfWeek;
            $candidate = $now->copy()->startOfDay();

            while ($candidate->dayOfWeek !== $target) {
                $candidate->addDay();
            }

            return $candidate;
        }

        if ($frequencyType === 'monthly' && $dayOfMonth !== null) {
            $target = max(1, min(31, (int) $dayOfMonth));
            $candidate = $now->copy()->startOfDay();
            $currentMonthLastDay = (int) $candidate->copy()->endOfMonth()->day;
            $candidate->day(min($target, $currentMonthLastDay));

            if ($candidate->lt($now->copy()->startOfDay())) {
                $candidate->addMonthNoOverflow();
                $nextMonthLastDay = (int) $candidate->copy()->endOfMonth()->day;
                $candidate->day(min($target, $nextMonthLastDay));
            }

            return $candidate;
        }

        return null;
    }
}

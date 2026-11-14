<?php

declare(strict_types=1);

namespace Modules\Supermarket\Jobs;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Supermarket\Models\SmSmartListSchedule;
use Modules\Supermarket\Notifications\SmartListShoppingReminderNotification;

final class ProcessSmartListScheduleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $scheduleId,
    ) {}

    public function handle(): void
    {
        // A scheduled shopping list is a reminder, NOT authorization to
        // place an irrevocable order or deduct stock.
        $notice = DB::transaction(function (): ?array {
            $schedule = SmSmartListSchedule::query()
                ->lockForUpdate()
                ->with(['smartList.user'])
                ->find($this->scheduleId);

            if ($schedule === null || ! $schedule->is_active ||
                $schedule->next_run_at === null || $schedule->next_run_at->isFuture()) {
                return null;
            }

            $list = $schedule->smartList;
            if ($list === null || ! $list->is_active || $list->store_id === null) {
                // Existing legacy schedules without a chosen store must not
                // repeatedly requeue and must never create an order.
                $schedule->update(['is_active' => false, 'next_run_at' => null]);
                return null;
            }

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $this->calculateNextRunAt($schedule),
                'is_active' => $schedule->frequency_type === 'once' ? false : true,
            ]);

            return [
                'user' => $list->user,
                'name' => $list->name,
                'list_id' => (int) $list->id,
                'store_id' => (int) $list->store_id,
            ];
        });

        if ($notice !== null && $notice['user'] !== null) {
            Notification::send($notice['user'], new SmartListShoppingReminderNotification(
                $notice['name'], $notice['list_id'], $notice['store_id'],
            ));
        }
    }

    private function calculateNextRunAt(SmSmartListSchedule $schedule): ?Carbon
    {
        if ($schedule->frequency_type === 'once') {
            return null;
        }

        $periodStart = $this->earliestPeriodStartTime($schedule->periods ?? []);

        if ($periodStart === null) {
            return null;
        }

        if ($schedule->frequency_type === 'weekly' && ! empty($schedule->week_days)) {
            return $this->nextWeeklyRunAt((array) $schedule->week_days, $periodStart, now());
        }

        if ($schedule->frequency_type === 'monthly' && ! empty($schedule->month_days)) {
            return $this->nextMonthlyRunAt((array) $schedule->month_days, $periodStart, now());
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     */
    private function earliestPeriodStartTime(array $periods): ?string
    {
        $normalized = array_values(array_filter(
            array_map(static function (array $period): ?string {
                $fromTime = (string) ($period['fromTime'] ?? $period['from_time'] ?? '');

                return $fromTime !== '' ? $fromTime : null;
            }, $periods)
        ));

        if ($normalized === []) {
            return null;
        }

        sort($normalized);

        return $normalized[0];
    }

    /**
     * @param  array<int, int>  $weekDays
     */
    private function nextWeeklyRunAt(array $weekDays, string $startTime, CarbonInterface $now): ?Carbon
    {
        $selectedWeekDays = array_values(array_unique(array_map(static fn (int $day): int => max(0, min(6, $day)), $weekDays)));

        for ($offset = 0; $offset <= 14; $offset++) {
            $candidateDate = $now->copy()->startOfDay()->addDays($offset);

            if (! in_array($candidateDate->dayOfWeek, $selectedWeekDays, true)) {
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
        $selectedMonthDays = array_values(array_unique(array_map(static fn (int $day): int => max(1, min(31, $day)), $monthDays)));

        for ($monthOffset = 0; $monthOffset <= 12; $monthOffset++) {
            $month = $now->copy()->startOfMonth()->addMonthsNoOverflow($monthOffset);
            $lastDay = (int) $month->copy()->endOfMonth()->day;

            foreach ($selectedMonthDays as $day) {
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

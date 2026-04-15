<?php

declare(strict_types=1);

namespace Modules\Supermarket\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmSmartListSchedule;
use Modules\Supermarket\Notifications\SmartListScheduledOrderFailedNotification;
use Modules\Supermarket\Notifications\SmartListScheduledOrderSentNotification;

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
        $schedule = SmSmartListSchedule::query()
            ->with(['smartList.items.masterProduct', 'smartList.user'])
            ->find($this->scheduleId);

        if ($schedule === null || ! $schedule->is_active || $schedule->next_run_at === null || $schedule->next_run_at->isFuture()) {
            return;
        }

        $smartList = $schedule->smartList;

        if ($smartList === null || ! $smartList->is_active || $smartList->store_id === null) {
            return;
        }

        $includedItems = $smartList->items->where('is_included', true)->values();

        if ($includedItems->isEmpty()) {
            $this->markFailedRun($schedule, $smartList->name, 'لا توجد عناصر مفعلة داخل القائمة الذكية.');

            return;
        }

        $masterProductIds = $includedItems
            ->pluck('master_product_id')
            ->filter()
            ->unique()
            ->values();

        $productsByMaster = SmProduct::query()
            ->where('store_id', $smartList->store_id)
            ->where('is_available', true)
            ->whereIn('master_product_id', $masterProductIds)
            ->get()
            ->keyBy('master_product_id');

        foreach ($includedItems as $item) {
            if ($item->master_product_id === null || ! $productsByMaster->has($item->master_product_id)) {
                $this->markFailedRun($schedule, $smartList->name, 'بعض المنتجات غير متوفرة حالياً في المتجر المحدد.');

                return;
            }
        }

        DB::transaction(function () use ($schedule, $smartList, $includedItems, $productsByMaster): void {
            $lockedSchedule = SmSmartListSchedule::query()->lockForUpdate()->find($schedule->id);

            if ($lockedSchedule === null || ! $lockedSchedule->is_active || $lockedSchedule->next_run_at === null || $lockedSchedule->next_run_at->isFuture()) {
                return;
            }

            $subtotal = 0.0;
            $orderItemsPayload = [];

            foreach ($includedItems as $item) {
                /** @var SmProduct $product */
                $product = $productsByMaster->get($item->master_product_id);
                $quantity = max(1, (int) round((float) $item->quantity));
                $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
                $lineTotal = $quantity * $unitPrice;

                $subtotal += $lineTotal;

                $orderItemsPayload[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'product_name' => $product->name,
                ];
            }

            $order = SmOrder::query()->create([
                'customer_id' => $smartList->user_id,
                'store_id' => $smartList->store_id,
                'order_number' => $this->generateOrderNumber(),
                'status' => SmOrderStatus::Pending->value,
                'pickup_mode' => SmPickupMode::ImmediatePickup->value,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'service_fee' => 0,
                'total_amount' => $subtotal,
            ]);

            foreach ($orderItemsPayload as $payload) {
                SmOrderItem::query()->create([
                    'order_id' => $order->id,
                    ...$payload,
                ]);
            }

            $lockedSchedule->update([
                'last_run_at' => now(),
                'next_run_at' => $this->calculateNextRunAt($lockedSchedule),
                'is_active' => $lockedSchedule->frequency_type === 'once' ? false : $lockedSchedule->is_active,
            ]);

            Notification::send(
                $smartList->user,
                new SmartListScheduledOrderSentNotification($smartList->name, (string) $order->order_number)
            );
        });
    }

    private function markFailedRun(SmSmartListSchedule $schedule, string $smartListName, string $reason): void
    {
        $schedule->update([
            'last_run_at' => now(),
            'next_run_at' => $this->calculateNextRunAt($schedule),
            'is_active' => $schedule->frequency_type === 'once' ? false : $schedule->is_active,
        ]);

        $user = $schedule->smartList?->user;

        if ($user !== null) {
            Notification::send($user, new SmartListScheduledOrderFailedNotification($smartListName, $reason));
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
    private function nextWeeklyRunAt(array $weekDays, string $startTime, Carbon $now): ?Carbon
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
    private function nextMonthlyRunAt(array $monthDays, string $startTime, Carbon $now): ?Carbon
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

    private function generateOrderNumber(): string
    {
        do {
            $value = 'SM-'.Str::upper(Str::random(10));
        } while (SmOrder::query()->where('order_number', $value)->exists());

        return $value;
    }
}

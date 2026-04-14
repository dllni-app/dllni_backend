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

        $base = now()->startOfDay();

        if ($schedule->frequency_type === 'weekly' && $schedule->day_of_week !== null) {
            $candidate = $base->copy()->addDay();

            while ($candidate->dayOfWeek !== $schedule->day_of_week) {
                $candidate->addDay();
            }

            return $candidate;
        }

        if ($schedule->frequency_type === 'monthly' && $schedule->day_of_month !== null) {
            $candidate = $base->copy()->addMonthNoOverflow();
            $lastDay = (int) $candidate->copy()->endOfMonth()->day;
            $candidate->day(min($schedule->day_of_month, $lastDay));

            return $candidate;
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

<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Log;
use Modules\Supermarket\Data\SmOrderData;
use Modules\Supermarket\Data\SmOrderRejectStatusData;
use Modules\Supermarket\Enums\RejectionType;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Notifications\ConsecutiveRejectionsAlertNotification;
use Modules\Supermarket\Notifications\OrderRejectedNotification;
use Modules\Supermarket\Notifications\StoreTrustWarningNotification;

final class SmOrderService
{
    private const TRUST_PENALTY_FAKE_ORDER = 20;

    private const TRUST_PENALTY_OUT_OF_STOCK = 5;

    private const TRUST_WARNING_THRESHOLD = 80;

    private const TRUST_REDUCTION_THRESHOLD = 60;

    private const TRUST_SUSPENSION_THRESHOLD = 40;

    private const CONSECUTIVE_REJECTION_LIMIT = 3;

    public function __construct(
        private readonly SmInventoryService $inventoryService
    ) {}

    public function store(SmOrderData $data): SmOrder
    {
        return DB::transaction(static function () use ($data) {
            $order = SmOrder::create($data->onlyModelAttributes());

            return $order;
        });
    }

    public function update(SmOrderData $data, SmOrder $order): SmOrder
    {
        return DB::transaction(static function () use ($data, $order) {
            tap($order)->update($data->onlyModelAttributes());

            return $order;
        });
    }

    /** @return array<int, array{hour:int,ordersCount:int}> */
    public function getHourlyOrderCounts(int $hours = 5): array
    {
        $currentHour = now()->startOfHour();
        $startHour = $currentHour->copy()->subHours($hours);

        $orders = SmOrder::query()
            ->whereBetween('created_at', [$startHour, $currentHour->copy()->endOfHour()])
            ->get(['created_at']);

        $orderCountsByHour = [];
        foreach ($orders as $order) {
            $hour = (int) $order->created_at->format('G');
            $orderCountsByHour[$hour] = ($orderCountsByHour[$hour] ?? 0) + 1;
        }

        $hourlyCounts = [];
        $cursorHour = $startHour->copy();
        while ($cursorHour->lte($currentHour)) {
            $hour = (int) $cursorHour->format('G');

            $hourlyCounts[] = [
                'hour' => $hour,
                'ordersCount' => (int) ($orderCountsByHour[$hour] ?? 0),
            ];

            $cursorHour = $cursorHour->addHour();
        }

        return $hourlyCounts;
    }

    /** @return array<string, array<string, int>> */
    public function getWeeklyOrderCountsByStatus(?int $storeId = null): array
    {
        $startOfWeek = now()->startOfWeek(Carbon::SATURDAY);
        $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();

        $ordersQuery = SmOrder::query()
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->whereIn('status', [SmOrderStatus::Pending, SmOrderStatus::Preparing, SmOrderStatus::Completed]);

        if ($storeId !== null && $storeId > 0) {
            $ordersQuery->where('store_id', $storeId);
        }

        $orders = $ordersQuery->get(['created_at', 'status']);

        $daysOfWeek = [
            0 => 'saturday',
            1 => 'sunday',
            2 => 'monday',
            3 => 'tuesday',
            4 => 'wednesday',
            5 => 'thursday',
            6 => 'friday',
        ];

        $statuses = ['pending', 'preparing', 'completed'];

        $weeklyCounts = [];
        foreach ($daysOfWeek as $day) {
            $weeklyCounts[$day] = [];
            foreach ($statuses as $status) {
                $weeklyCounts[$day][$status] = 0;
            }
        }

        foreach ($orders as $order) {
            $dayOfWeek = $order->created_at->copy()->startOfWeek(Carbon::SATURDAY)->diffInDays($order->created_at->startOfDay());
            $dayName = $daysOfWeek[$dayOfWeek];
            $status = $order->status->value;

            if (in_array($status, $statuses)) {
                $weeklyCounts[$dayName][$status]++;
            }
        }

        return $weeklyCounts;
    }

    public function acceptOrder(SmOrder $order): SmOrder
    {
        return DB::transaction(function () use ($order): SmOrder {
            if ($order->status !== SmOrderStatus::Pending) {
                throw new Exception(
                    "Cannot accept order {$order->order_number}. Order must be in PENDING status, currently in {$order->status->value}"
                );
            }

            $this->inventoryService->deductStockForOrder($order);

            $order->update([
                'status' => SmOrderStatus::Accepted,
            ]);

            return $order->refresh();
        });
    }

    public function handOverToCourier(SmOrder $order, ?int $actorUserId): SmOrder
    {
        return DB::transaction(function () use ($order, $actorUserId): SmOrder {
            if ($order->status === SmOrderStatus::PickedUp) {
                return $order->refresh();
            }

            if ($order->status !== SmOrderStatus::ReadyForPickup) {
                throw new Exception(
                    "Cannot hand over order {$order->order_number}. Order must be in ready_for_pickup status, currently in {$order->status->value}"
                );
            }

            $order->update([
                'status' => SmOrderStatus::PickedUp,
                'picked_up_at' => now(),
            ]);

            SmOrderStatusLog::query()->create([
                'order_id' => $order->id,
                'from_status' => SmOrderStatus::ReadyForPickup->value,
                'to_status' => SmOrderStatus::PickedUp->value,
                'notes' => 'Handed to courier.',
                'changed_by_user_id' => $actorUserId,
            ]);

            return $order->refresh();
        });
    }

    public function rejectOrder(SmOrder $order, SmOrderRejectStatusData $data): SmOrder
    {
        return DB::transaction(function () use ($order, $data): SmOrder {
            if ($order->status !== SmOrderStatus::Pending) {
                throw new Exception(
                    "Cannot reject order {$order->order_number}. Order must be in PENDING status, currently in {$order->status->value}"
                );
            }

            $order->update([
                'status' => SmOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $data->reason,
            ]);

            $trustPenalty = $this->calculateTrustPenalty(RejectionType::from($data->rejectionType));

            if ($trustPenalty > 0) {
                $this->updateStoreTrustScore($order->store, $trustPenalty);
            }

            $this->checkConsecutiveRejections($order->store);

            try {
                Notification::send($order->customer, new OrderRejectedNotification($order, $data->reason));
            } catch (Exception $e) {
                Log::warning("Failed to send order rejection notification: {$e->getMessage()}");
            }

            return $order->refresh();
        });
    }

    private function calculateTrustPenalty(RejectionType $type): int
    {
        return match ($type) {
            RejectionType::FakeOrder => self::TRUST_PENALTY_FAKE_ORDER,
            RejectionType::OutOfStock => self::TRUST_PENALTY_OUT_OF_STOCK,
            RejectionType::Other => 0,
        };
    }

    private function updateStoreTrustScore(SmStore $store, int $penalty): void
    {
        $newTrustScore = max(0, $store->trust_score - $penalty);
        $store->update(['trust_score' => $newTrustScore]);

        if ($newTrustScore <= self::TRUST_SUSPENSION_THRESHOLD) {
            $store->update([
                'suspension_until' => now()->addDays(30),
            ]);
        } elseif ($newTrustScore <= self::TRUST_REDUCTION_THRESHOLD) {
            $store->update(['is_featured' => false]);
        }

        if ($newTrustScore <= self::TRUST_WARNING_THRESHOLD) {
            try {
                Notification::send($store->owner, new StoreTrustWarningNotification($store, $newTrustScore));
            } catch (Exception $e) {
                Log::warning("Failed to send store trust warning notification: {$e->getMessage()}");
            }
        }
    }

    private function checkConsecutiveRejections(SmStore $store): void
    {
        $recentCancelledCount = SmOrder::query()
            ->where('store_id', $store->id)
            ->where('status', SmOrderStatus::Cancelled)
            ->orderBy('created_at', 'desc')
            ->limit(self::CONSECUTIVE_REJECTION_LIMIT + 1)
            ->count();

        if ($recentCancelledCount >= self::CONSECUTIVE_REJECTION_LIMIT) {
            try {
                Notification::send($store->owner, new ConsecutiveRejectionsAlertNotification($store, $recentCancelledCount));
            } catch (Exception $e) {
                Log::warning("Failed to send consecutive rejections alert: {$e->getMessage()}");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Log;
use InvalidArgumentException;
use Modules\Delivery\Services\MerchantOrderDeliveryService;
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
        private readonly SmInventoryService $inventoryService,
        private readonly MerchantOrderDeliveryService $merchantDelivery,
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

    public function acceptOrder(SmOrder $order, ?int $actorUserId = null, ?int $preparationMinutes = null): SmOrder
    {
        $accepted = DB::transaction(function () use ($order, $actorUserId, $preparationMinutes): SmOrder {
            $order = SmOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== SmOrderStatus::Pending) {
                throw new Exception(
                    "Cannot accept order {$order->order_number}. Order must be in PENDING status, currently in {$order->status->value}"
                );
            }

            $this->inventoryService->deductStockForOrder($order);

            $acceptedAt = now();
            $order->update([
                'status' => SmOrderStatus::Accepted,
                'accepted_at' => $acceptedAt,
                'estimated_preparation_minutes' => $preparationMinutes,
                'estimated_ready_at' => $preparationMinutes !== null ? $acceptedAt->copy()->addMinutes($preparationMinutes) : null,
            ]);

            $this->logStatus($order, SmOrderStatus::Pending, SmOrderStatus::Accepted, 'Order accepted by store owner.', $actorUserId);

            return $order->refresh();
        });

        $this->merchantDelivery->accepted($accepted);

        return $accepted->refresh();
    }

    public function markPreparing(SmOrder $order, ?int $actorUserId): SmOrder
    {
        $updated = DB::transaction(function () use ($order, $actorUserId): SmOrder {
            $order = SmOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === SmOrderStatus::Preparing) {
                return $order->refresh();
            }

            if ($order->status !== SmOrderStatus::Accepted) {
                throw new Exception(
                    "Cannot mark order {$order->order_number} as preparing. Order must be in accepted status, currently in {$order->status->value}"
                );
            }

            $order->update([
                'status' => SmOrderStatus::Preparing,
            ]);

            $this->logStatus($order, SmOrderStatus::Accepted, SmOrderStatus::Preparing, 'Order preparation started.', $actorUserId);

            return $order->refresh();
        });

        $this->merchantDelivery->statusUpdated($updated);

        return $updated->refresh();
    }

    public function markReadyForPickup(SmOrder $order, ?int $actorUserId): SmOrder
    {
        $order = DB::transaction(function () use ($order, $actorUserId): SmOrder {
            $order = SmOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === SmOrderStatus::ReadyForPickup) {
                return $order->refresh();
            }

            if (! in_array($order->status, [SmOrderStatus::Accepted, SmOrderStatus::Preparing], true)) {
                throw new Exception(
                    "Cannot mark order {$order->order_number} as ready for pickup. Order must be accepted or preparing, currently in {$order->status->value}"
                );
            }

            $from = $order->status;

            $order->update([
                'status' => SmOrderStatus::ReadyForPickup,
                'ready_for_pickup_at' => now(),
            ]);

            $this->logStatus($order, $from, SmOrderStatus::ReadyForPickup, 'Order is ready for courier pickup.', $actorUserId);
            return $order->refresh();
        });

        $this->merchantDelivery->ready($order);

        return $order->refresh();
    }

    public function updatePreparationEstimate(SmOrder $order, ?int $preparationMinutes): SmOrder
    {
        $updated = DB::transaction(function () use ($order, $preparationMinutes): SmOrder {
            $lockedOrder = SmOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($lockedOrder->status, [SmOrderStatus::Accepted, SmOrderStatus::Preparing], true)) {
                throw new InvalidArgumentException('Preparation estimates can only be changed while accepted or preparing.');
            }

            $lockedOrder->forceFill([
                'estimated_preparation_minutes' => $preparationMinutes,
                'estimated_ready_at' => $preparationMinutes !== null ? now()->addMinutes($preparationMinutes) : null,
            ])->save();

            return $lockedOrder->fresh();
        });

        $this->merchantDelivery->preparationUpdated($updated);

        return $updated->refresh();
    }

    public function cancelAfterAcceptance(SmOrder $order, string $reason, ?int $actorUserId): SmOrder
    {
        $cancelled = DB::transaction(function () use ($order, $reason, $actorUserId): SmOrder {
            $lockedOrder = SmOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status === SmOrderStatus::Cancelled) {
                return $lockedOrder->refresh();
            }

            if (! in_array($lockedOrder->status, [
                SmOrderStatus::Accepted,
                SmOrderStatus::Preparing,
                SmOrderStatus::ReadyForPickup,
            ], true)) {
                throw new InvalidArgumentException('Only accepted, preparing, or ready-for-pickup orders can be cancelled by the store.');
            }

            $from = $lockedOrder->status;
            $lockedOrder->forceFill([
                'status' => SmOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->logStatus($lockedOrder, $from, SmOrderStatus::Cancelled, $reason, $actorUserId);

            return $lockedOrder->refresh();
        });

        $this->merchantDelivery->cancelled($cancelled, $reason, $actorUserId);

        return $cancelled->refresh();
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

            $this->logStatus($order, SmOrderStatus::ReadyForPickup, SmOrderStatus::PickedUp, 'Handed to courier.', $actorUserId);

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
                Log::warning("Failed to send consecutive rejection alert: {$e->getMessage()}");
            }
        }
    }

    private function logStatus(SmOrder $order, ?SmOrderStatus $from, SmOrderStatus $to, ?string $note = null, ?int $actorUserId = null): void
    {
        SmOrderStatusLog::query()->create([
            'order_id' => $order->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'notes' => $note,
            'changed_by_user_id' => $actorUserId,
        ]);
    }
}

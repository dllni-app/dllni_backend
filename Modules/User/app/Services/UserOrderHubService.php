<?php

declare(strict_types=1);

namespace Modules\User\Services;

use BackedEnum;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Http\Resources\DeliveryOrderResource;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Services\MerchantOrderDeliveryService;
use Modules\Delivery\Support\DeliveryPresentation;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UserOrderHubService
{
    public function __construct(
        private readonly UserRestaurantCartService $restaurantCarts,
        private readonly UserSupermarketCartService $supermarketCarts,
        private readonly MerchantOrderDeliveryService $merchantDelivery,
    ) {}

    public function list(int $userId, string $section = 'all', ?string $status = null, ?string $search = null, ?int $restaurantId = null, int $perPage = 20, int $page = 1): array
    {
        if ($section === 'restaurant') {
            return $this->paginateRestaurant($userId, $status, $search, $restaurantId, $perPage, $page);
        }
        if ($section === 'supermarket') {
            return $this->paginateSupermarket($userId, $status, $search, $perPage, $page);
        }

        $restaurantOrders = Order::query()
            ->where('user_id', $userId)
            ->with($this->restaurantOrderEagerLoads())
            ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('order_number', 'like', '%'.$search.'%'))
            ->latest()
            ->get();

        $supermarketOrders = SmOrder::query()
            ->where('customer_id', $userId)
            ->with($this->supermarketOrderEagerLoads())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('order_number', 'like', '%'.$search.'%'))
            ->latest()
            ->get();

        $all = $restaurantOrders
            ->map(fn (Order $order): array => $this->toPayload('restaurant', $order))
            ->concat($supermarketOrders->map(fn (SmOrder $order): array => $this->toPayload('supermarket', $order)))
            ->sortByDesc('createdAt')
            ->values();

        return $this->paginateMappedCollection($all, $perPage, $page);
    }

    public function show(int $userId, string $section, int $orderId): array
    {
        return $this->toPayload($section, $this->findOrderModel($userId, $section, $orderId));
    }

    public function tracking(int $userId, string $section, int $orderId): array
    {
        $order = $this->findOrderModel($userId, $section, $orderId);
        $payload = $this->toPayload($section, $order);
        $deliveryOrder = $this->deliveryOrderFor($order);

        if ($deliveryOrder instanceof DeliveryOrder) {
            $deliveryOrder->loadMissing(['company', 'driver.user', 'driver.latestLocation', 'events']);
            $tracking = DeliveryPresentation::orderTracking($deliveryOrder);

            return [
                'deliveryOrderId' => $deliveryOrder->id,
                'delivery' => DeliveryOrderResource::make($deliveryOrder)->resolve(),
                'eta' => $tracking['eta'] ?? null,
                'map' => $tracking['map'] ?? null,
                'timeline' => $tracking['timeline'] ?? [],
                'merchant' => $payload['merchant'],
                'actions' => $payload['actions'],
            ];
        }

        return [
            'eta' => ['minutes' => $this->estimateEtaMinutes($section, $payload), 'text' => $this->estimateEtaText($section, $payload)],
            'map' => ['enabled' => false, 'lat' => null, 'lng' => null],
            'timeline' => $payload['timeline'],
            'merchant' => $payload['merchant'],
            'actions' => $payload['actions'],
        ];
    }

    public function cancel(int $userId, string $section, int $orderId, ?string $reason): array
    {
        if ($section === 'restaurant') {
            // Authorize before waiting on a delivery lock; recheck under the source lock below.
            Order::query()->where('user_id', $userId)->select('id')->findOrFail($orderId);

            return DB::transaction(function () use ($userId, $orderId, $reason): array {
                $deliveryOrder = DeliveryOrder::query()
                    ->where('source_type', DeliveryOrderCreationService::SOURCE_RESTAURANT_ORDER)
                    ->where('source_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                $order = Order::query()
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->findOrFail($orderId);
                $status = $order->status?->value ?? (string) $order->status;

                if ($status === OrderStatus::Cancelled->value) {
                    return $this->restaurantCancellationPayload($order, false);
                }

                if (! in_array($status, [OrderStatus::Pending->value, OrderStatus::Accepted->value, OrderStatus::Preparing->value], true)) {
                    throw new ConflictHttpException('ORDER_CANNOT_BE_CANCELLED');
                }

                if ($deliveryOrder !== null) {
                    $this->assertDeliveryCanBeCancelled($deliveryOrder);

                    $this->merchantDelivery->cancelled(
                        $order,
                        $reason ?? 'Cancelled by customer',
                        $userId,
                    );

                    $order->refresh();
                    if (($order->status?->value ?? (string) $order->status) !== OrderStatus::Cancelled->value) {
                        throw new ConflictHttpException('ORDER_CANCELLATION_NOT_APPLIED');
                    }

                    return $this->restaurantCancellationPayload($order->fresh($this->restaurantOrderEagerLoads()), true);
                }

                if (($order->order_type?->value ?? (string) $order->order_type) === 'delivery') {
                    throw new ConflictHttpException('DELIVERY_ORDER_NOT_AVAILABLE');
                }

                $order->update([
                    'status' => OrderStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]);
                OrderStatusLog::query()->create([
                    'order_id' => $order->id,
                    'from_status' => $status,
                    'to_status' => OrderStatus::Cancelled->value,
                    'note' => $reason,
                ]);

                return $this->restaurantCancellationPayload($order->fresh($this->restaurantOrderEagerLoads()), true);
            });
        }

        $order = $this->findOrderModel($userId, $section, $orderId);
        $status = $order->status?->value ?? (string) $order->status;
        if (in_array($status, [SmOrderStatus::Cancelled->value, SmOrderStatus::Completed->value], true)) {
            return $this->toPayload($section, $order);
        }
        $order->update(['status' => SmOrderStatus::Cancelled->value, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        SmOrderStatusLog::query()->create(['order_id' => $order->id, 'from_status' => $status, 'to_status' => SmOrderStatus::Cancelled->value, 'notes' => $reason, 'changed_by_user_id' => $userId]);

        return $this->toPayload($section, $order->fresh($this->supermarketOrderEagerLoads()));
    }

    public function schedule(int $userId, string $section, int $orderId, string $scheduledAt): array
    {
        $changes = ['pickup_mode' => 'scheduled_pickup', 'pickup_scheduled_for' => $scheduledAt];
        if ($section === 'restaurant') {
            return DB::transaction(function () use ($userId, $orderId, $changes, $scheduledAt): array {
                $order = Order::query()->where('user_id', $userId)->lockForUpdate()->findOrFail($orderId);
                $status = $order->status?->value ?? (string) $order->status;
                if (! $this->actionsFor($status)['canReschedule'] || ($order->order_type?->value ?? (string) $order->order_type) !== 'pickup') {
                    throw new ConflictHttpException('ORDER_CANNOT_BE_RESCHEDULED');
                }
                app(RestaurantPickupWindowPolicy::class)->assertScheduled($order->restaurant()->with('operatingHours')->firstOrFail(), $scheduledAt);
                $order->update($changes);

                return $this->toPayload('restaurant', $order->fresh($this->restaurantOrderEagerLoads()));
            });
        }
        $order = $this->findOrderModel($userId, $section, $orderId);
        $order->update($changes);

        return $this->toPayload($section, $order->fresh($this->supermarketOrderEagerLoads()));
    }

    public function reorder(int $userId, string $section, int $orderId): array
    {
        $order = $this->findOrderModel($userId, $section, $orderId);
        if ($section === 'restaurant') {
            $count = 0;
            foreach ($order->orderItems as $item) {
                $modifierIds = DB::table('order_item_modifier')->where('order_item_id', $item->id)->pluck('modifier_id')->map(fn ($id): int => (int) $id)->values()->all();
                $this->restaurantCarts->addItem(userId: $userId, productId: (int) $item->product_id, quantity: (int) $item->quantity, modifierIds: $modifierIds, substituteProductId: $item->substitute_product_id === null ? null : (int) $item->substitute_product_id, note: $item->special_instructions, quantityMode: 'increment');
                $count++;
            }

            return ['itemsAdded' => $count];
        }
        $count = 0;
        foreach ($order->items as $item) {
            $this->supermarketCarts->addItem(userId: $userId, productId: (int) $item->product_id, quantity: (int) $item->quantity);
            $count++;
        }

        return ['itemsAdded' => $count];
    }

    public function slots(string $section, int $merchantId, ?string $fulfillmentType, string $date): array
    {
        $slots = [];
        for ($hour = 9; $hour <= 21; $hour++) {
            $slots[] = ['id' => Str::uuid()->toString(), 'section' => $section, 'merchantId' => $merchantId, 'fulfillmentType' => $fulfillmentType ?? 'pickup', 'startAt' => sprintf('%s %02d:00:00', $date, $hour), 'endAt' => sprintf('%s %02d:00:00', $date, min($hour + 1, 22)), 'available' => true];
        }

        return ['slots' => $slots];
    }

    private function assertDeliveryCanBeCancelled(DeliveryOrder $deliveryOrder): void
    {
        $status = DeliveryOrderStatus::tryFrom((string) $deliveryOrder->status);
        $uncancellableStatuses = [
            DeliveryOrderStatus::PickedUp,
            DeliveryOrderStatus::ReturningToMerchant,
            DeliveryOrderStatus::ReturnedToMerchant,
            DeliveryOrderStatus::Delivered,
            DeliveryOrderStatus::Completed,
            DeliveryOrderStatus::Cancelled,
        ];

        if ($status === null || in_array($status, $uncancellableStatuses, true)) {
            throw new ConflictHttpException('DELIVERY_ORDER_CANNOT_BE_CANCELLED');
        }
    }

    private function restaurantCancellationPayload(Order $order, bool $cancelledNow): array
    {
        return $this->toPayload('restaurant', $order) + ['_cancelledNow' => $cancelledNow];
    }

    private function restaurantActions(Order $order, string $status): array
    {
        $actions = $this->actionsFor($status);
        $isPickup = ($order->order_type?->value ?? (string) $order->order_type) === 'pickup';
        $actions['canReschedule'] = $actions['canReschedule'] && $isPickup;
        if (! $isPickup) {
            $delivery = $this->deliveryOrderFor($order);
            $deliveryStatus = $delivery?->status instanceof BackedEnum ? $delivery->status->value : $delivery?->status;
            $cannotCancelDelivery = $delivery === null || DeliveryOrderStatus::tryFrom((string) $deliveryStatus) === null || in_array($deliveryStatus, [
                DeliveryOrderStatus::PickedUp->value,
                DeliveryOrderStatus::ReturningToMerchant->value,
                DeliveryOrderStatus::ReturnedToMerchant->value,
                DeliveryOrderStatus::Delivered->value,
                DeliveryOrderStatus::Completed->value,
                DeliveryOrderStatus::Cancelled->value,
            ], true);
            if ($cannotCancelDelivery) {
                $actions['canCancel'] = false;
            }
        }

        return $actions;
    }

    private function restaurantActionReasons(Order $order, string $status): array
    {
        $actions = $this->restaurantActions($order, $status);
        $reasons = [];
        if (! $actions['canCancel']) {
            $reasons['canCancel'] = in_array($status, ['pending', 'accepted', 'preparing'], true)
                ? 'DELIVERY_ALREADY_ADVANCED' : 'ORDER_STATUS_LOCKED';
        }
        if (! $actions['canReschedule']) {
            $reasons['canReschedule'] = ($order->order_type?->value ?? (string) $order->order_type) !== 'pickup'
                ? 'FULFILLMENT_NOT_PICKUP' : 'ORDER_STATUS_LOCKED';
        }
        if (! $actions['canReorder']) {
            $reasons['canReorder'] = 'ORDER_STATUS_LOCKED';
        }

        return $reasons;
    }

    private function findOrderModel(int $userId, string $section, int $orderId): Order|SmOrder
    {
        if ($section === 'restaurant') {
            return Order::query()->where('user_id', $userId)->with($this->restaurantOrderEagerLoads())->findOrFail($orderId);
        }

        return SmOrder::query()->where('customer_id', $userId)->with($this->supermarketOrderEagerLoads())->findOrFail($orderId);
    }

    private function paginateRestaurant(int $userId, ?string $status, ?string $search, ?int $restaurantId, int $perPage, int $page): array
    {
        $paginator = Order::query()
            ->where('user_id', $userId)
            ->with($this->restaurantOrderEagerLoads())
            ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('order_number', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate(perPage: $perPage, page: $page);

        return $this->paginateMappedCollection($paginator->getCollection()->map(fn (Order $order): array => $this->toPayload('restaurant', $order)), $perPage, $page, $paginator->total(), $paginator);
    }

    private function paginateSupermarket(int $userId, ?string $status, ?string $search, int $perPage, int $page): array
    {
        $paginator = SmOrder::query()
            ->where('customer_id', $userId)
            ->with($this->supermarketOrderEagerLoads())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('order_number', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate(perPage: $perPage, page: $page);

        return $this->paginateMappedCollection($paginator->getCollection()->map(fn (SmOrder $order): array => $this->toPayload('supermarket', $order)), $perPage, $page, $paginator->total(), $paginator);
    }

    private function paginateMappedCollection(Collection $orders, int $perPage, int $page, ?int $forcedTotal = null, ?LengthAwarePaginator $existingPaginator = null): array
    {
        $total = $forcedTotal ?? $orders->count();
        $items = $existingPaginator ? $orders->values()->all() : $orders->forPage($page, $perPage)->values()->all();
        $paginator = $existingPaginator ?? new LengthAwarePaginator($items, $total, $perPage, $page);

        return ['data' => $items, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()], 'links' => ['first' => $paginator->url(1), 'last' => $paginator->url($paginator->lastPage()), 'prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()]];
    }

    private function toPayload(string $section, Order|SmOrder $order): array
    {
        $deliveryOrder = $this->deliveryOrderFor($order);
        $deliverySummary = $deliveryOrder ? DeliveryPresentation::merchantSummary($order) : null;
        if ($section === 'supermarket' && $deliveryOrder !== null && $deliverySummary !== null) {
            // The merchant order can be ready while courier dispatch has stopped.
            // These are distinct lifecycles; show the linked delivery status here.
            $deliveryStatus = $deliveryOrder->status instanceof BackedEnum
                ? $deliveryOrder->status->value
                : (string) $deliveryOrder->status;
            $deliverySummary['status'] = $deliveryStatus;
            $deliverySummary['statusLabelAr'] = DeliveryPresentation::statusLabelAr($deliveryStatus);
            $deliverySummary['stopReason'] = $deliveryOrder->stop_reason;
        }

        if ($section === 'restaurant') {
            $status = $order->status?->value ?? (string) $order->status;
            $timeline = $order->orderStatusLogs->map(fn (OrderStatusLog $log): array => ['fromStatus' => $log->from_status, 'toStatus' => $log->to_status, 'note' => $log->note, 'changedAt' => $log->created_at?->toDateTimeString(), 'changedAtIso' => $log->created_at?->toIso8601String()])->values()->all();

            return ['id' => $order->id, 'deliveryOrderId' => $deliveryOrder?->id, 'deliverySummary' => $deliverySummary, 'section' => 'restaurant', 'orderNumber' => $order->order_number, 'status' => $status, 'statusLabel' => Str::of($status)->replace('_', ' ')->title()->toString(), 'merchant' => ['id' => $order->restaurant?->id, 'name' => $order->restaurant?->name, 'primaryImageUrl' => $order->restaurant?->getFirstMediaUrl('primary-image') ?: null, 'bannerImageUrl' => $order->restaurant?->getFirstMediaUrl('banner-image') ?: null], 'fulfillment' => ['type' => $order->order_type?->value ?? $order->order_type, 'receiveMode' => $order->pickup_mode?->value ?? $order->pickup_mode, 'scheduledAt' => $order->pickup_scheduled_for?->toDateTimeString(), 'scheduledAtIso' => $order->pickup_scheduled_for?->toIso8601String()], 'amounts' => ['subtotal' => (float) ($order->subtotal ?? 0), 'discount' => (float) ($order->discount_amount ?? 0), 'serviceFee' => (float) ($order->service_fee ?? 0), 'deliveryFee' => (float) ($deliveryOrder?->delivery_fee ?? 0), 'tax' => (float) ($order->tax_amount ?? 0), 'total' => (float) ($order->total_amount ?? 0)], 'items' => $order->orderItems->map(function (OrderItem $item): array {
                $product = $item->product;

                return ['id' => $item->id, 'productId' => $item->product_id, 'name' => $item->product_name ?? $product?->name, 'modifierSnapshot' => $item->modifier_snapshot ?? [], 'primaryImageUrl' => $product?->getFirstMediaUrl('primary-image') ?: null, 'images' => $product !== null ? $product->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all() : [], 'quantity' => $item->quantity, 'unitPrice' => (float) ($item->unit_price ?? 0), 'totalPrice' => (float) ($item->total_price ?? 0), 'note' => $item->special_instructions];
            })->values()->all(), 'timeline' => $timeline, 'actions' => $this->restaurantActions($order, $status), 'actionReasons' => $this->restaurantActionReasons($order, $status), 'createdAt' => $order->created_at?->toISOString(), 'updatedAt' => $order->updated_at?->toISOString()];
        }

        $status = $order->status?->value ?? (string) $order->status;
        $timeline = $order->statusLogs->map(fn (SmOrderStatusLog $log): array => ['fromStatus' => $log->from_status, 'toStatus' => $log->to_status, 'note' => $log->notes, 'changedAt' => $log->created_at?->toDateTimeString()])->values()->all();

        return ['id' => $order->id, 'deliveryOrderId' => $deliveryOrder?->id, 'deliverySummary' => $deliverySummary, 'section' => 'supermarket', 'orderNumber' => $order->order_number, 'status' => $status, 'statusLabel' => Str::of($status)->replace('_', ' ')->title()->toString(), 'merchant' => ['id' => $order->store?->id, 'name' => $order->store?->name], 'fulfillment' => ['type' => $deliveryOrder ? 'delivery' : 'pickup', 'receiveMode' => $order->pickup_mode?->value ?? $order->pickup_mode, 'scheduledAt' => $order->pickup_scheduled_for?->toDateTimeString()], 'amounts' => ['subtotal' => (float) ($order->subtotal ?? 0), 'discount' => (float) ($order->discount_amount ?? 0), 'serviceFee' => (float) ($order->service_fee ?? 0), 'deliveryFee' => (float) ($order->delivery_fee ?? $deliveryOrder?->delivery_fee ?? 0), 'tax' => 0.0, 'total' => (float) ($order->total_amount ?? 0)], 'items' => $order->items->map(fn ($item): array => ['id' => $item->id, 'productId' => $item->product_id, 'name' => $item->product_name ?? $item->product?->name, 'quantity' => $item->quantity, 'unitPrice' => (float) ($item->unit_price ?? 0), 'totalPrice' => (float) ($item->total_price ?? 0), 'note' => null])->values()->all(), 'timeline' => $timeline, 'actions' => $this->actionsFor($status), 'createdAt' => $order->created_at?->toISOString(), 'updatedAt' => $order->updated_at?->toISOString()];
    }

    private function restaurantOrderEagerLoads(): array
    {
        return ['restaurant.media', 'userAddress', 'orderItems.product.media', 'orderStatusLogs', 'deliveryOrder.driver.user', 'deliveryOrder.driver.latestLocation', 'deliveryOrder.events'];
    }

    private function supermarketOrderEagerLoads(): array
    {
        return ['store', 'items.product', 'statusLogs', 'deliveryOrder.driver.user', 'deliveryOrder.driver.latestLocation', 'deliveryOrder.events'];
    }

    private function deliveryOrderFor(Order|SmOrder $order): ?DeliveryOrder
    {
        if ($order->relationLoaded('deliveryOrder')) {
            return $order->deliveryOrder instanceof DeliveryOrder ? $order->deliveryOrder : null;
        }
        $deliveryOrder = $order->deliveryOrder()->with(['driver.user', 'driver.latestLocation', 'events'])->first();

        return $deliveryOrder instanceof DeliveryOrder ? $deliveryOrder : null;
    }

    private function actionsFor(string $status): array
    {
        $canCancel = in_array($status, ['pending', 'accepted', 'preparing'], true);

        return ['canCancel' => $canCancel, 'canReorder' => in_array($status, ['completed', 'cancelled'], true), 'canReschedule' => $canCancel];
    }

    private function estimateEtaMinutes(string $section, array $payload): ?int
    {
        return ($payload['status'] ?? null) === 'completed' ? 0 : ($section === 'restaurant' ? 25 : 35);
    }

    private function estimateEtaText(string $section, array $payload): ?string
    {
        $minutes = $this->estimateEtaMinutes($section, $payload);

        return $minutes === null ? null : sprintf('%d min', $minutes);
    }
}

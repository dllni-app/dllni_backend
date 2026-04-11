<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderStatusLog;

final class UserOrderHubService
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    public function list(
        int $userId,
        string $section = 'all',
        ?string $status = null,
        ?string $search = null,
        ?int $restaurantId = null,
        int $perPage = 20,
        int $page = 1,
    ): array {
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
            ->with(['store', 'items.product', 'statusLogs'])
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

    /**
     * @return array<string, mixed>
     */
    public function show(int $userId, string $section, int $orderId): array
    {
        return $this->toPayload($section, $this->findOrderModel($userId, $section, $orderId));
    }

    /**
     * @return array<string, mixed>
     */
    public function tracking(int $userId, string $section, int $orderId): array
    {
        $payload = $this->show($userId, $section, $orderId);

        return [
            'eta' => [
                'minutes' => $this->estimateEtaMinutes($section, $payload),
                'text' => $this->estimateEtaText($section, $payload),
            ],
            'map' => [
                'enabled' => false,
                'lat' => null,
                'lng' => null,
            ],
            'timeline' => $payload['timeline'],
            'merchant' => $payload['merchant'],
            'actions' => $payload['actions'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $userId, string $section, int $orderId, ?string $reason): array
    {
        $order = $this->findOrderModel($userId, $section, $orderId);

        if ($section === 'restaurant') {
            /** @var Order $order */
            $status = $order->status?->value ?? (string) $order->status;
            if (in_array($status, [OrderStatus::Cancelled->value, OrderStatus::Completed->value], true)) {
                return $this->toPayload($section, $order);
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

            return $this->toPayload($section, $order->fresh($this->restaurantOrderEagerLoads()));
        }

        /** @var SmOrder $order */
        $status = $order->status?->value ?? (string) $order->status;
        if (in_array($status, [SmOrderStatus::Cancelled->value, SmOrderStatus::Completed->value], true)) {
            return $this->toPayload($section, $order);
        }

        $order->update([
            'status' => SmOrderStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        SmOrderStatusLog::query()->create([
            'order_id' => $order->id,
            'from_status' => $status,
            'to_status' => SmOrderStatus::Cancelled->value,
            'notes' => $reason,
            'changed_by_user_id' => $userId,
        ]);

        return $this->toPayload($section, $order->fresh(['store', 'items.product', 'statusLogs']));
    }

    /**
     * @return array<string, mixed>
     */
    public function schedule(int $userId, string $section, int $orderId, string $scheduledAt): array
    {
        $order = $this->findOrderModel($userId, $section, $orderId);

        $changes = [
            'pickup_mode' => 'scheduled_pickup',
            'pickup_scheduled_for' => $scheduledAt,
        ];

        if ($section === 'restaurant') {
            /** @var Order $order */
            $order->update($changes);

            return $this->toPayload($section, $order->fresh($this->restaurantOrderEagerLoads()));
        }

        /** @var SmOrder $order */
        $order->update($changes);

        return $this->toPayload($section, $order->fresh(['store', 'items.product', 'statusLogs']));
    }

    /**
     * @return array{itemsAdded: int}
     */
    public function reorder(int $userId, string $section, int $orderId): array
    {
        $order = $this->findOrderModel($userId, $section, $orderId);

        if ($section === 'restaurant') {
            /** @var Order $order */
            $cart = Cart::query()->firstOrCreate([
                'user_id' => $userId,
                'restaurant_id' => $order->restaurant_id,
            ]);

            $count = 0;
            foreach ($order->orderItems as $item) {
                $cartItem = CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $item->product_id,
                    'substitute_product_id' => $item->substitute_product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'special_instructions' => $item->special_instructions,
                ]);

                $modifierRows = DB::table('order_item_modifier')
                    ->where('order_item_id', $item->id)
                    ->get(['modifier_id', 'price'])
                    ->map(fn ($row): array => [
                        'cart_item_id' => $cartItem->id,
                        'modifier_id' => (int) $row->modifier_id,
                        'price' => (float) $row->price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($modifierRows !== []) {
                    DB::table('cart_item_modifier')->insert($modifierRows);
                }

                $count++;
            }

            return ['itemsAdded' => $count];
        }

        /** @var SmOrder $order */
        $cart = SmCart::query()->firstOrCreate([
            'user_id' => $userId,
            'store_id' => $order->store_id,
        ]);

        $count = 0;
        foreach ($order->items as $item) {
            SmCartItem::query()->create([
                'cart_id' => $cart->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ]);
            $count++;
        }

        return ['itemsAdded' => $count];
    }

    /**
     * @return array{slots: array<int, array<string, mixed>>}
     */
    public function slots(
        string $section,
        int $merchantId,
        ?string $fulfillmentType,
        string $date,
    ): array {
        $slots = [];
        for ($hour = 9; $hour <= 21; $hour++) {
            $start = sprintf('%s %02d:00:00', $date, $hour);
            $end = sprintf('%s %02d:00:00', $date, min($hour + 1, 22));
            $slots[] = [
                'id' => Str::uuid()->toString(),
                'section' => $section,
                'merchantId' => $merchantId,
                'fulfillmentType' => $fulfillmentType ?? 'pickup',
                'startAt' => $start,
                'endAt' => $end,
                'available' => true,
            ];
        }

        return ['slots' => $slots];
    }

    private function findOrderModel(int $userId, string $section, int $orderId): Order|SmOrder
    {
        if ($section === 'restaurant') {
            return Order::query()
                ->where('user_id', $userId)
                ->with($this->restaurantOrderEagerLoads())
                ->findOrFail($orderId);
        }

        return SmOrder::query()
            ->where('customer_id', $userId)
            ->with(['store', 'items.product', 'statusLogs'])
            ->findOrFail($orderId);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    private function paginateRestaurant(
        int $userId,
        ?string $status,
        ?string $search,
        ?int $restaurantId,
        int $perPage,
        int $page,
    ): array {
        $paginator = Order::query()
            ->where('user_id', $userId)
            ->with($this->restaurantOrderEagerLoads())
            ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('order_number', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => $paginator->getCollection()->map(fn (Order $order): array => $this->toPayload('restaurant', $order))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    private function paginateSupermarket(
        int $userId,
        ?string $status,
        ?string $search,
        int $perPage,
        int $page,
    ): array {
        $paginator = SmOrder::query()
            ->where('customer_id', $userId)
            ->with(['store', 'items.product', 'statusLogs'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('order_number', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => $paginator->getCollection()->map(fn (SmOrder $order): array => $this->toPayload('supermarket', $order))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $orders
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    private function paginateMappedCollection(Collection $orders, int $perPage, int $page): array
    {
        $total = $orders->count();
        $items = $orders->forPage($page, $perPage)->values()->all();
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page);

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(string $section, Order|SmOrder $order): array
    {
        if ($section === 'restaurant') {
            /** @var Order $order */
            $status = $order->status?->value ?? (string) $order->status;
            $timeline = $order->orderStatusLogs->map(fn (OrderStatusLog $log): array => [
                'fromStatus' => $log->from_status,
                'toStatus' => $log->to_status,
                'note' => $log->note,
                'changedAt' => $log->created_at?->toDateTimeString(),
            ])->values()->all();

            return [
                'id' => $order->id,
                'section' => 'restaurant',
                'orderNumber' => $order->order_number,
                'status' => $status,
                'statusLabel' => Str::of($status)->replace('_', ' ')->title()->toString(),
                'merchant' => [
                    'id' => $order->restaurant?->id,
                    'name' => $order->restaurant?->name,
                    'primaryImageUrl' => $order->restaurant?->getFirstMediaUrl('primary-image') ?: null,
                    'bannerImageUrl' => $order->restaurant?->getFirstMediaUrl('banner-image') ?: null,
                ],
                'fulfillment' => [
                    'type' => $order->order_type?->value ?? $order->order_type,
                    'receiveMode' => $order->pickup_mode?->value ?? $order->pickup_mode,
                    'scheduledAt' => $order->pickup_scheduled_for?->toDateTimeString(),
                ],
                'amounts' => [
                    'subtotal' => (float) ($order->subtotal ?? 0),
                    'discount' => (float) ($order->discount_amount ?? 0),
                    'serviceFee' => (float) ($order->service_fee ?? 0),
                    'tax' => (float) ($order->tax_amount ?? 0),
                    'total' => (float) ($order->total_amount ?? 0),
                ],
                'items' => $order->orderItems->map(function (OrderItem $item): array {
                    $product = $item->product;

                    return [
                        'id' => $item->id,
                        'productId' => $item->product_id,
                        'name' => $product?->name,
                        'primaryImageUrl' => $product?->getFirstMediaUrl('primary-image') ?: null,
                        'images' => $product !== null
                            ? $product->getMedia('images')->map(fn ($media) => $media->getUrl())->values()->all()
                            : [],
                        'quantity' => $item->quantity,
                        'unitPrice' => (float) ($item->unit_price ?? 0),
                        'totalPrice' => (float) ($item->total_price ?? 0),
                        'note' => $item->special_instructions,
                    ];
                })->values()->all(),
                'timeline' => $timeline,
                'actions' => $this->actionsFor($status),
                'createdAt' => $order->created_at?->toISOString(),
                'updatedAt' => $order->updated_at?->toISOString(),
            ];
        }

        /** @var SmOrder $order */
        $status = $order->status?->value ?? (string) $order->status;
        $timeline = $order->statusLogs->map(fn (SmOrderStatusLog $log): array => [
            'fromStatus' => $log->from_status,
            'toStatus' => $log->to_status,
            'note' => $log->notes,
            'changedAt' => $log->created_at?->toDateTimeString(),
        ])->values()->all();

        return [
            'id' => $order->id,
            'section' => 'supermarket',
            'orderNumber' => $order->order_number,
            'status' => $status,
            'statusLabel' => Str::of($status)->replace('_', ' ')->title()->toString(),
            'merchant' => [
                'id' => $order->store?->id,
                'name' => $order->store?->name,
            ],
            'fulfillment' => [
                'type' => 'pickup',
                'receiveMode' => $order->pickup_mode?->value ?? $order->pickup_mode,
                'scheduledAt' => $order->pickup_scheduled_for?->toDateTimeString(),
            ],
            'amounts' => [
                'subtotal' => (float) ($order->subtotal ?? 0),
                'discount' => (float) ($order->discount_amount ?? 0),
                'serviceFee' => (float) ($order->service_fee ?? 0),
                'tax' => 0.0,
                'total' => (float) ($order->total_amount ?? 0),
            ],
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'productId' => $item->product_id,
                'name' => $item->product_name ?? $item->product?->name,
                'quantity' => $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'totalPrice' => (float) ($item->total_price ?? 0),
                'note' => null,
            ])->values()->all(),
            'timeline' => $timeline,
            'actions' => $this->actionsFor($status),
            'createdAt' => $order->created_at?->toISOString(),
            'updatedAt' => $order->updated_at?->toISOString(),
        ];
    }

    /**
     * @return list<string>
     */
    private function restaurantOrderEagerLoads(): array
    {
        return [
            'restaurant.media',
            'orderItems.product.media',
            'orderStatusLogs',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function actionsFor(string $status): array
    {
        $canCancel = in_array($status, ['pending', 'accepted', 'preparing'], true);

        return [
            'canCancel' => $canCancel,
            'canReorder' => in_array($status, ['completed', 'cancelled'], true),
            'canReschedule' => $canCancel,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function estimateEtaMinutes(string $section, array $payload): ?int
    {
        if (($payload['status'] ?? null) === 'completed') {
            return 0;
        }

        return $section === 'restaurant' ? 25 : 35;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function estimateEtaText(string $section, array $payload): ?string
    {
        $minutes = $this->estimateEtaMinutes($section, $payload);

        return $minutes === null ? null : sprintf('%d min', $minutes);
    }
}

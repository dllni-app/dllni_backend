<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Modules\Supermarket\Models\SmProduct;

final class UserSupermarketCheckoutPipelineService
{
    public function preview(
        int $userId,
        string $fulfillmentType,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $couponCode,
        ?string $note,
        ?array $merchantCoupons = null,
    ): array {
        $cart = $this->loadCart($userId);
        $groups = $this->validatedGroups($cart);

        return $this->checkoutPayload(
            checkoutBatchNumber: null,
            groups: $groups,
            couponMap: $this->couponMap($merchantCoupons),
            legacyCouponCode: $couponCode,
            fulfillmentType: $fulfillmentType,
            receiveMode: $receiveMode,
            scheduledAt: $scheduledAt,
            note: $note,
            orders: [],
        );
    }

    public function place(
        int $userId,
        string $fulfillmentType,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $couponCode,
        ?string $note,
        ?array $merchantCoupons = null,
    ): array {
        return DB::transaction(function () use ($userId, $fulfillmentType, $receiveMode, $scheduledAt, $couponCode, $note, $merchantCoupons): array {
            $cart = $this->loadCart($userId);
            $this->lockProductsForCart($cart);
            $cart->load(['items.product.store']);

            $groups = $this->validatedGroups($cart);
            $couponMap = $this->couponMap($merchantCoupons);
            $batchNumber = 'SMC-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999);
            $orders = [];

            foreach ($groups as $storeId => $items) {
                $subtotal = $this->subtotal($items);
                $couponCodeForStore = $this->couponCodeForStore((int) $storeId, $couponMap, $couponCode, $groups->count());
                $couponSnapshot = $this->couponSnapshot((int) $storeId, $couponCodeForStore, $subtotal);
                $coupon = $couponSnapshot['model'];
                $discount = (float) $couponSnapshot['discount'];
                $serviceFee = 0.0;

                $order = SmOrder::query()->create([
                    'customer_id' => $userId,
                    'store_id' => (int) $storeId,
                    'coupon_id' => $coupon?->id,
                    'checkout_batch_number' => $batchNumber,
                    'checkout_orders_count' => $groups->count(),
                    'order_number' => $this->orderNumber(),
                    'status' => SmOrderStatus::Pending->value,
                    'pickup_mode' => $receiveMode === 'scheduled'
                        ? SmPickupMode::ScheduledPickup->value
                        : SmPickupMode::ImmediatePickup->value,
                    'pickup_scheduled_for' => $scheduledAt,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'service_fee' => $serviceFee,
                    'total_amount' => max(0.0, $subtotal - $discount) + $serviceFee,
                    'special_instructions' => $note,
                ]);

                foreach ($items as $item) {
                    SmOrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => (float) ($item->unit_price ?? 0) * (int) $item->quantity,
                        'product_name' => $item->product?->name,
                    ]);
                }

                SmOrderStatusLog::query()->create([
                    'order_id' => $order->id,
                    'from_status' => null,
                    'to_status' => SmOrderStatus::Pending->value,
                    'notes' => 'Order placed by customer from checkout batch '.$batchNumber.'.',
                    'changed_by_user_id' => $userId,
                ]);

                $orders[] = $order->fresh(['store', 'items.product', 'statusLogs']);
            }

            $payload = $this->checkoutPayload(
                checkoutBatchNumber: $batchNumber,
                groups: $groups,
                couponMap: $couponMap,
                legacyCouponCode: $couponCode,
                fulfillmentType: $fulfillmentType,
                receiveMode: $receiveMode,
                scheduledAt: $scheduledAt,
                note: $note,
                orders: $orders,
            );

            $cart->delete();

            return $payload;
        });
    }

    private function loadCart(int $userId): SmCart
    {
        $cart = SmCart::query()
            ->where('user_id', $userId)
            ->with(['items.product.store'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
        }

        return $cart;
    }

    private function lockProductsForCart(SmCart $cart): void
    {
        $productIds = $cart->items->pluck('product_id')->filter()->unique()->values();

        if ($productIds->isNotEmpty()) {
            SmProduct::query()->whereIn('id', $productIds->all())->lockForUpdate()->get();
        }
    }

    private function validatedGroups(SmCart $cart): Collection
    {
        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product || ! $product->store_id) {
                throw ValidationException::withMessages([
                    'cart' => ['One or more cart items are no longer linked to a valid supermarket store.'],
                ]);
            }

            if (! $product->is_available) {
                throw ValidationException::withMessages(['cart' => ["Product {$product->id} is not available."]]);
            }

            if ((int) $product->stock_quantity < (int) $item->quantity) {
                throw ValidationException::withMessages(['cart' => ["Product {$product->id} does not have enough available stock."]]);
            }
        }

        return $cart->items->groupBy(fn ($item): int => (int) $item->product->store_id);
    }

    private function couponMap(?array $merchantCoupons): array
    {
        $map = [];

        foreach ($merchantCoupons ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $merchantId = (int) ($row['merchantId'] ?? $row['merchant_id'] ?? 0);
            $code = $row['couponCode'] ?? $row['coupon_code'] ?? null;
            $code = is_string($code) ? mb_trim($code) : '';

            if ($merchantId > 0 && $code !== '') {
                $map[$merchantId] = $code;
            }
        }

        return $map;
    }

    private function couponCodeForStore(int $storeId, array $couponMap, ?string $legacyCouponCode, int $groupsCount): ?string
    {
        if (isset($couponMap[$storeId])) {
            return $couponMap[$storeId];
        }

        if ($groupsCount === 1 && is_string($legacyCouponCode) && mb_trim($legacyCouponCode) !== '') {
            return mb_trim($legacyCouponCode);
        }

        return null;
    }

    private function subtotal(Collection $items): float
    {
        return (float) $items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);
    }

    private function checkoutPayload(
        ?string $checkoutBatchNumber,
        Collection $groups,
        array $couponMap,
        ?string $legacyCouponCode,
        string $fulfillmentType,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $note,
        array $orders,
    ): array {
        $ordersPayload = array_map(fn (SmOrder $order): array => $this->orderPayload($order, $fulfillmentType), $orders);
        $summary = ['subtotal' => 0.0, 'discount' => 0.0, 'serviceFee' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        $merchantGroups = [];

        foreach ($groups as $storeId => $items) {
            $store = $items->first()?->product?->store;
            $subtotal = $this->subtotal($items);
            $couponCodeForStore = $this->couponCodeForStore((int) $storeId, $couponMap, $legacyCouponCode, $groups->count());
            $couponSnapshot = $this->couponSnapshot((int) $storeId, $couponCodeForStore, $subtotal);
            $discount = (float) $couponSnapshot['discount'];
            $serviceFee = 0.0;
            $total = max(0.0, $subtotal - $discount) + $serviceFee;

            $summary['subtotal'] += $subtotal;
            $summary['discount'] += $discount;
            $summary['serviceFee'] += $serviceFee;
            $summary['total'] += $total;

            $merchantGroups[] = [
                'merchant' => ['id' => $store?->id, 'name' => $store?->name],
                'itemsCount' => $items->count(),
                'items' => $items->map(fn ($item): array => [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'name' => $item->product?->name,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) ($item->unit_price ?? 0),
                    'totalPrice' => round((float) ($item->unit_price ?? 0) * (int) $item->quantity, 2),
                    'note' => null,
                ])->values()->all(),
                'coupon' => $couponSnapshot['payload'],
                'amounts' => [
                    'subtotal' => round($subtotal, 2),
                    'discount' => round($discount, 2),
                    'serviceFee' => round($serviceFee, 2),
                    'tax' => 0.0,
                    'total' => round($total, 2),
                ],
            ];
        }

        $payload = [
            'checkoutBatchNumber' => $checkoutBatchNumber,
            'section' => 'supermarket',
            'isMultiMerchant' => $groups->count() > 1,
            'createdOrdersCount' => count($ordersPayload),
            'orders' => $ordersPayload,
            'merchantGroups' => $merchantGroups,
            'amounts' => [
                'subtotal' => round($summary['subtotal'], 2),
                'discount' => round($summary['discount'], 2),
                'serviceFee' => round($summary['serviceFee'], 2),
                'tax' => 0.0,
                'total' => round($summary['total'], 2),
            ],
            'fulfillment' => ['type' => $fulfillmentType, 'receiveMode' => $receiveMode, 'scheduledAt' => $scheduledAt],
            'note' => $note,
        ];

        return $ordersPayload === [] ? $payload : array_merge($ordersPayload[0], $payload);
    }

    private function couponSnapshot(int $storeId, ?string $couponCode, float $subtotal): array
    {
        $couponCode = is_string($couponCode) ? mb_trim($couponCode) : '';
        if ($couponCode === '') {
            return [
                'payload' => ['couponCode' => null, 'isAvailable' => false, 'reason' => 'not_provided', 'coupon' => null],
                'discount' => 0.0,
                'model' => null,
            ];
        }

        $coupon = SmCoupon::query()->where('store_id', $storeId)->where('code', $couponCode)->first();
        [$isAvailable, $reason] = $this->couponAvailability($coupon, $subtotal);
        $discount = $isAvailable && $coupon ? $this->computeDiscount($coupon, $subtotal) : 0.0;

        return [
            'payload' => [
                'couponCode' => $couponCode,
                'isAvailable' => $isAvailable,
                'reason' => $reason,
                'coupon' => $coupon ? [
                    'type' => $coupon->type,
                    'value' => $coupon->value !== null ? (float) $coupon->value : null,
                    'percent' => $coupon->percent !== null ? (float) $coupon->percent : null,
                    'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                    'maxDiscountAmount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
                ] : null,
            ],
            'discount' => $discount,
            'model' => $isAvailable ? $coupon : null,
        ];
    }

    private function couponAvailability(?SmCoupon $coupon, float $subtotal): array
    {
        if (! $coupon) {
            return [false, 'not_found'];
        }
        if (! $coupon->is_active) {
            return [false, 'inactive'];
        }
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return [false, 'not_started'];
        }
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return [false, 'expired'];
        }
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return [false, 'min_order_not_met'];
        }
        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) {
            return [false, 'usage_limit_reached'];
        }

        return [true, 'ok'];
    }

    private function computeDiscount(SmCoupon $coupon, float $subtotal): float
    {
        if ($coupon->type === 'percentage') {
            $amount = $subtotal * ((float) ($coupon->percent ?? 0) / 100);
            return round($coupon->max_discount_amount !== null ? min($amount, (float) $coupon->max_discount_amount) : $amount, 2);
        }

        return round(min((float) ($coupon->value ?? 0), $subtotal), 2);
    }

    private function orderNumber(): string
    {
        return 'SM-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999);
    }

    private function orderPayload(SmOrder $order, string $fulfillmentType): array
    {
        $status = $order->status?->value ?? (string) $order->status;
        $pickupMode = $order->pickup_mode?->value ?? (string) $order->pickup_mode;

        return [
            'id' => $order->id,
            'section' => 'supermarket',
            'checkoutBatchNumber' => $order->checkout_batch_number,
            'checkoutOrdersCount' => (int) ($order->checkout_orders_count ?? 1),
            'orderNumber' => $order->order_number,
            'status' => $status,
            'statusLabel' => Str::of($status)->replace('_', ' ')->title()->toString(),
            'merchant' => ['id' => $order->store?->id, 'name' => $order->store?->name],
            'fulfillment' => [
                'type' => $fulfillmentType,
                'receiveMode' => str_contains($pickupMode, 'scheduled') ? 'scheduled' : 'immediate',
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
                'quantity' => (int) $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'totalPrice' => (float) ($item->total_price ?? 0),
                'note' => null,
            ])->values()->all(),
            'timeline' => $order->statusLogs->map(fn (SmOrderStatusLog $log): array => [
                'fromStatus' => $log->from_status,
                'toStatus' => $log->to_status,
                'note' => $log->notes,
                'changedAt' => $log->created_at?->toDateTimeString(),
            ])->values()->all(),
            'actions' => [
                'canCancel' => in_array($status, ['pending', 'accepted', 'preparing'], true),
                'canReorder' => in_array($status, ['completed', 'cancelled'], true),
                'canReschedule' => in_array($status, ['pending', 'accepted', 'preparing'], true),
            ],
            'createdAt' => $order->created_at?->toISOString(),
            'updatedAt' => $order->updated_at?->toISOString(),
        ];
    }
}

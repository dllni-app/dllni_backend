<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Resturants\Models\PromoCode;

final class UserRestaurantCheckoutPipelineService
{
    public function __construct(
        private readonly RestaurantCheckoutService $checkoutService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        int $userId,
        int $merchantId,
        string $fulfillmentType,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $couponCode,
        ?string $note,
    ): array {
        $cart = Cart::query()
            ->where('user_id', $userId)
            ->where('restaurant_id', $merchantId)
            ->with(['items.product'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Cart is empty.'],
            ]);
        }

        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->total_price ?? 0));
        $discount = $this->computeDiscount($merchantId, $couponCode, $subtotal);
        $serviceFee = 0.0;
        $tax = 0.0;
        $total = max(0.0, $subtotal - $discount) + $serviceFee + $tax;

        return [
            'fulfillment' => [
                'type' => $fulfillmentType,
                'receiveMode' => $receiveMode,
                'scheduledAt' => $scheduledAt,
            ],
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'serviceFee' => round($serviceFee, 2),
                'tax' => round($tax, 2),
                'total' => round($total, 2),
            ],
            'items' => $cart->items->map(fn ($item): array => [
                'id' => $item->id,
                'productId' => $item->product_id,
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'totalPrice' => (float) ($item->total_price ?? 0),
                'note' => $item->special_instructions,
            ])->values()->all(),
            'note' => $note,
        ];
    }

    public function place(
        int $userId,
        int $merchantId,
        string $fulfillmentType,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $couponCode,
        ?string $note,
    ): Order {
        $order = $this->checkoutService->checkout(
            userId: $userId,
            restaurantId: $merchantId,
            orderType: $fulfillmentType,
            pickupMode: $receiveMode === 'scheduled'
                ? RestaurantPickupMode::ScheduledPickup->value
                : RestaurantPickupMode::ImmediatePickup->value,
            pickupScheduledFor: $scheduledAt,
            promoCode: $couponCode,
            specialInstructions: $note,
        );

        OrderStatusLog::query()->firstOrCreate([
            'order_id' => $order->id,
            'to_status' => OrderStatus::Pending->value,
        ], [
            'from_status' => null,
            'note' => 'Order placed by customer.',
        ]);

        return $order->fresh(['restaurant', 'orderItems.product', 'orderStatusLogs']);
    }

    private function computeDiscount(int $merchantId, ?string $couponCode, float $subtotal): float
    {
        if (! is_string($couponCode) || trim($couponCode) === '') {
            return 0.0;
        }

        $coupon = PromoCode::query()
            ->where('restaurant_id', $merchantId)
            ->where('code', $couponCode)
            ->first();

        if (! $coupon || ! $coupon->is_active) {
            return 0.0;
        }

        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return 0.0;
        }

        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return 0.0;
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return 0.0;
        }

        if ($coupon->discount_type?->value === 'percentage') {
            return round($subtotal * ((float) $coupon->discount_value / 100), 2);
        }

        return round(min((float) $coupon->discount_value, $subtotal), 2);
    }
}


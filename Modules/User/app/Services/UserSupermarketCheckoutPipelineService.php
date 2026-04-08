<?php

declare(strict_types=1);

namespace Modules\User\Services;

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

final class UserSupermarketCheckoutPipelineService
{
    /**
     * @return array<string, mixed>
     */
    public function preview(
        int $userId,
        int $merchantId,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $couponCode,
        ?string $note,
    ): array {
        $cart = SmCart::query()
            ->where('user_id', $userId)
            ->where('store_id', $merchantId)
            ->with(['items.product'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Cart is empty.'],
            ]);
        }

        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);
        $discount = $this->computeDiscount($merchantId, $couponCode, $subtotal);
        $serviceFee = 0.0;
        $total = max(0.0, $subtotal - $discount) + $serviceFee;

        return [
            'fulfillment' => [
                'type' => 'pickup',
                'receiveMode' => $receiveMode,
                'scheduledAt' => $scheduledAt,
            ],
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'serviceFee' => round($serviceFee, 2),
                'tax' => 0.0,
                'total' => round($total, 2),
            ],
            'items' => $cart->items->map(fn ($item): array => [
                'id' => $item->id,
                'productId' => $item->product_id,
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'totalPrice' => round((float) ($item->unit_price ?? 0) * (int) $item->quantity, 2),
            ])->values()->all(),
            'note' => $note,
        ];
    }

    public function place(
        int $userId,
        int $merchantId,
        string $receiveMode,
        ?string $scheduledAt,
        ?string $couponCode,
        ?string $note,
    ): SmOrder {
        return DB::transaction(function () use ($userId, $merchantId, $receiveMode, $scheduledAt, $couponCode, $note): SmOrder {
            $cart = SmCart::query()
                ->where('user_id', $userId)
                ->where('store_id', $merchantId)
                ->with(['items.product'])
                ->first();

            if (! $cart || $cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Cart is empty.'],
                ]);
            }

            $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);
            $coupon = $this->findCoupon($merchantId, $couponCode, $subtotal);
            $discount = $coupon ? $this->computeDiscount($merchantId, $couponCode, $subtotal) : 0.0;
            $serviceFee = 0.0;
            $total = max(0.0, $subtotal - $discount) + $serviceFee;

            $order = SmOrder::query()->create([
                'customer_id' => $userId,
                'store_id' => $merchantId,
                'coupon_id' => $coupon?->id,
                'order_number' => 'SM-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
                'status' => SmOrderStatus::Pending->value,
                'pickup_mode' => $receiveMode === 'scheduled'
                    ? SmPickupMode::ScheduledPickup->value
                    : SmPickupMode::ImmediatePickup->value,
                'pickup_scheduled_for' => $scheduledAt,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'service_fee' => $serviceFee,
                'total_amount' => $total,
                'special_instructions' => $note,
            ]);

            foreach ($cart->items as $item) {
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
                'notes' => 'Order placed by customer.',
                'changed_by_user_id' => $userId,
            ]);

            $cart->delete();

            return $order->fresh(['store', 'items.product', 'statusLogs']);
        });
    }

    private function computeDiscount(int $merchantId, ?string $couponCode, float $subtotal): float
    {
        $coupon = $this->findCoupon($merchantId, $couponCode, $subtotal);
        if (! $coupon) {
            return 0.0;
        }

        if ($coupon->type === 'percentage') {
            $amount = $subtotal * ((float) ($coupon->percent ?? 0) / 100);
            if ($coupon->max_discount_amount !== null) {
                $amount = min($amount, (float) $coupon->max_discount_amount);
            }

            return round($amount, 2);
        }

        return round(min((float) ($coupon->value ?? 0), $subtotal), 2);
    }

    private function findCoupon(int $merchantId, ?string $couponCode, float $subtotal): ?SmCoupon
    {
        if (! is_string($couponCode) || trim($couponCode) === '') {
            return null;
        }

        $coupon = SmCoupon::query()
            ->where('store_id', $merchantId)
            ->where('code', $couponCode)
            ->first();

        if (! $coupon || ! $coupon->is_active) {
            return null;
        }

        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return null;
        }

        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return null;
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return null;
        }

        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) {
            return null;
        }

        return $coupon;
    }
}


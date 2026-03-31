<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\PromoCode;

final class RestaurantCheckoutService
{
    public function checkout(
        int $userId,
        int $restaurantId,
        string $orderType,
        ?string $pickupMode = null,
        ?string $pickupScheduledFor = null,
        ?string $promoCode = null,
        ?string $specialInstructions = null,
    ): Order {
        return DB::transaction(function () use ($userId, $restaurantId, $orderType, $pickupMode, $pickupScheduledFor, $promoCode, $specialInstructions) {
            $cart = Cart::query()
                ->where('user_id', $userId)
                ->where('restaurant_id', $restaurantId)
                ->with(['items', 'items.product'])
                ->first();

            if (! $cart || $cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Cart is empty.'],
                ]);
            }

            $subtotal = (float) $cart->items->sum(fn ($item) => (float) ($item->total_price ?? 0));

            $promo = null;
            $discountAmount = 0.0;

            if (is_string($promoCode) && $promoCode !== '') {
                $promo = PromoCode::query()
                    ->where('code', $promoCode)
                    ->where('restaurant_id', $restaurantId)
                    ->first();

                if (! $promo || ! $this->promoIsValid($promo, $subtotal)) {
                    throw ValidationException::withMessages([
                        'promoCode' => ['Invalid promo code.'],
                    ]);
                }

                $discountAmount = $this->calculateDiscount($promo, $subtotal);
            }

            $taxAmount = 0.0;
            $serviceFee = 0.0;
            $totalAmount = max(0.0, $subtotal - $discountAmount) + $taxAmount + $serviceFee;

            $order = Order::create([
                'user_id' => $userId,
                'restaurant_id' => $restaurantId,
                'promo_code_id' => $promo?->id,
                'order_number' => 'ORD-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
                'status' => OrderStatus::Pending->value,
                'order_type' => $orderType,
                'pickup_mode' => $pickupMode ?? RestaurantPickupMode::ImmediatePickup->value,
                'pickup_scheduled_for' => $pickupScheduledFor,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'service_fee' => $serviceFee,
                'total_amount' => $totalAmount,
                'special_instructions' => $specialInstructions,
            ]);

            foreach ($cart->items as $cartItem) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cartItem->product_id,
                    'substitute_product_id' => $cartItem->substitute_product_id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'total_price' => $cartItem->total_price,
                    'special_instructions' => $cartItem->special_instructions,
                ]);

                $modifierRows = DB::table('cart_item_modifier')
                    ->where('cart_item_id', $cartItem->id)
                    ->get(['modifier_id', 'price'])
                    ->map(fn ($row) => [
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => (int) $row->modifier_id,
                        'price' => (float) $row->price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($modifierRows !== []) {
                    DB::table('order_item_modifier')->insert($modifierRows);
                }
            }

            $cart->delete();

            return $order;
        });
    }

    private function promoIsValid(PromoCode $promo, float $subtotal): bool
    {
        if (! $promo->is_active) {
            return false;
        }

        if ($promo->starts_at && now()->lessThan($promo->starts_at)) {
            return false;
        }

        if ($promo->ends_at && now()->greaterThan($promo->ends_at)) {
            return false;
        }

        if ($promo->min_order_amount !== null && $subtotal < (float) $promo->min_order_amount) {
            return false;
        }

        if ($promo->usage_limit !== null && (int) $promo->usage_count >= (int) $promo->usage_limit) {
            return false;
        }

        return true;
    }

    private function calculateDiscount(PromoCode $promo, float $subtotal): float
    {
        return match ($promo->discount_type) {
            DiscountType::Percentage => round($subtotal * ((float) $promo->discount_value / 100), 2),
            DiscountType::FixedAmount => min((float) $promo->discount_value, $subtotal),
            default => 0.0,
        };
    }
}
